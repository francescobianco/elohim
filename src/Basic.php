<?php
/**
 * Elohim BASIC — interprete minimale con stato completamente serializzabile.
 *
 * Tutto ciò che serve per riprendere l'esecuzione vive in $this->m (machine state),
 * un array puro JSON-friendly. Nessun riferimento a risorse locali del nodo.
 */

final class BasicError extends RuntimeException {}

final class Basic
{
    /** @var array machine state (serializzabile) */
    public array $m;

    /** @var array<int,array> token per riga, cache locale (non fa parte dello stato) */
    private array $tokens = [];

    /** @var array<int,int> numero di riga BASIC => indice */
    private array $lineIndex = [];

    /** @var callable(string $port, string $text): void */
    private $emit;

    private array $toks = [];
    private int $pos = 0;

    /** Crea lo stato iniziale S0 a partire dal sorgente. */
    public static function boot(string $source, array $inputs = []): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $source) as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            if (!preg_match('/^(\d+)\s*(.*)$/', $raw, $mm)) {
                throw new BasicError("Riga senza numero: $raw");
            }
            $lines[(int)$mm[1]] = $mm[2];
        }
        ksort($lines);

        return [
            'program' => array_map(fn($n, $t) => [$n, $t], array_keys($lines), array_values($lines)),
            'pc'      => [0, 0],          // [indice riga, posizione token]
            'vars'    => new stdClass(),
            'gosub'   => [],
            'for'     => [],
            'inputs'  => array_values($inputs),
            'status'  => 'running',       // running | sleeping | halted | error
            'wake_at' => 0,
            'steps'   => 0,
            'error'   => null,
        ];
    }

    public function __construct(array $machine, callable $emit)
    {
        $this->m = $machine;
        $this->m['vars'] = (array)$this->m['vars'];
        $this->emit = $emit;
        foreach ($this->m['program'] as $i => [$n, $text]) {
            $this->lineIndex[$n] = $i;
        }
    }

    /** Esegue al massimo $quantum istruzioni. Ritorna il numero di istruzioni eseguite. */
    public function run(int $quantum): int
    {
        $this->m['status'] = 'running';
        $done = 0;
        try {
            while ($done < $quantum && $this->m['status'] === 'running') {
                [$li, $p] = $this->m['pc'];
                if ($li >= count($this->m['program'])) {
                    $this->m['status'] = 'halted';
                    break;
                }
                $this->toks = $this->lineTokens($li);
                $this->pos = $p;

                if ($this->pos >= count($this->toks)) {
                    $this->m['pc'] = [$li + 1, 0];
                    continue;
                }
                if ($this->peek() === ':') {
                    $this->m['pc'] = [$li, $p + 1];
                    continue;
                }

                // pc di default: fine dello statement corrente
                $jump = $this->statement($li);
                $this->m['pc'] = $jump ?? [$li, $this->pos];
                $done++;
                $this->m['steps']++;
            }
        } catch (BasicError $e) {
            $line = $this->m['program'][$this->m['pc'][0]][0] ?? '?';
            $this->m['status'] = 'error';
            $this->m['error'] = "line $line: " . $e->getMessage();
        }
        return $done;
    }

    public function state(): array
    {
        $s = $this->m;
        $s['vars'] = (object)$s['vars'];
        return $s;
    }

    // ------------------------------------------------------------ statements

    /** Esegue uno statement. Ritorna un nuovo pc se c'è un salto, altrimenti null. */
    private function statement(int $li): ?array
    {
        $t = $this->next();
        switch ($t) {
            case 'REM':
                return [$li + 1, 0];

            case 'LET':
                $this->assign($this->identifier());
                return null;

            case 'PRINT':
            case '?':
                $this->doPrint('OUT');
                return null;

            case 'SEND':
                $port = (string)$this->expr();
                $this->expect(',');
                $this->doPrint($port);
                return null;

            case 'INPUT':
                if (is_string($this->peek()) && str_starts_with($this->peek(), '"')) {
                    ($this->emit)('OUT', substr($this->next(), 1));
                    $this->expect(';');
                }
                $var = $this->identifier();
                if (!$this->m['inputs']) {
                    throw new BasicError('INPUT: mailbox vuota');
                }
                $v = array_shift($this->m['inputs']);
                $this->m['vars'][$var] = str_ends_with($var, '$') ? (string)$v : (float)$v + 0;
                return null;

            case 'IF':
                $cond = $this->expr();
                $this->expect('THEN');
                if (!$this->truthy($cond)) {
                    return [$li + 1, 0];
                }
                if (is_int($this->peek()) || is_float($this->peek())) {
                    return $this->gotoLine((int)$this->next());
                }
                return $this->statement($li);

            case 'GOTO':
                return $this->gotoLine((int)$this->expr());

            case 'GOSUB':
                $target = (int)$this->expr();
                $this->m['gosub'][] = [$li, $this->pos];
                return $this->gotoLine($target);

            case 'RETURN':
                if (!$this->m['gosub']) throw new BasicError('RETURN senza GOSUB');
                return array_pop($this->m['gosub']);

            case 'FOR':
                $var = $this->identifier();
                $this->expect('=');
                $this->m['vars'][$var] = $this->expr();
                $this->expect('TO');
                $limit = $this->expr();
                $step = 1;
                if ($this->peek() === 'STEP') {
                    $this->next();
                    $step = $this->expr();
                }
                $this->m['for'][] = ['var' => $var, 'limit' => $limit, 'step' => $step, 'pc' => [$li, $this->pos]];
                return null;

            case 'NEXT':
                if ($this->peek() !== null && $this->peek() !== ':') $this->identifier();
                $f = end($this->m['for']);
                if ($f === false) throw new BasicError('NEXT senza FOR');
                $v = $this->m['vars'][$f['var']] + $f['step'];
                $this->m['vars'][$f['var']] = $v;
                if (($f['step'] >= 0 && $v <= $f['limit']) || ($f['step'] < 0 && $v >= $f['limit'])) {
                    return $f['pc'];
                }
                array_pop($this->m['for']);
                return null;

            case 'SLEEP':
                $secs = (float)$this->expr();
                $this->m['wake_at'] = microtime(true) + $secs;
                $this->m['status'] = 'sleeping';
                return null;

            case 'YIELD':
                $this->m['status'] = 'yield';
                return null;

            case 'END':
            case 'STOP':
                $this->m['status'] = 'halted';
                return null;

            default:
                if (is_string($t) && preg_match('/^[A-Z][A-Z0-9]*\$?$/', $t)) {
                    $this->assign($t);
                    return null;
                }
                throw new BasicError('Statement sconosciuto: ' . json_encode($t));
        }
    }

    private function assign(string $var): void
    {
        $this->expect('=');
        $v = $this->expr();
        if (str_ends_with($var, '$') !== is_string($v)) {
            throw new BasicError("Tipo errato per $var");
        }
        $this->m['vars'][$var] = $v;
    }

    private function doPrint(string $port): void
    {
        $out = '';
        $newline = true;
        while (($p = $this->peek()) !== null && $p !== ':' && $p !== 'ELSE') {
            if ($p === ';') { $this->next(); $newline = false; continue; }
            if ($p === ',') { $this->next(); $out .= "\t"; $newline = false; continue; }
            $out .= $this->fmt($this->expr());
            $newline = true;
        }
        ($this->emit)($port, $out . ($newline ? '' : ''));
    }

    private function gotoLine(int $n): array
    {
        if (!isset($this->lineIndex[$n])) throw new BasicError("Riga $n inesistente");
        return [$this->lineIndex[$n], 0];
    }

    // ----------------------------------------------------------- expressions

    private function expr(): mixed { return $this->orExpr(); }

    private function orExpr(): mixed
    {
        $l = $this->andExpr();
        while ($this->peek() === 'OR') { $this->next(); $r = $this->andExpr(); $l = ($this->truthy($l) || $this->truthy($r)) ? -1 : 0; }
        return $l;
    }

    private function andExpr(): mixed
    {
        $l = $this->notExpr();
        while ($this->peek() === 'AND') { $this->next(); $r = $this->notExpr(); $l = ($this->truthy($l) && $this->truthy($r)) ? -1 : 0; }
        return $l;
    }

    private function notExpr(): mixed
    {
        if ($this->peek() === 'NOT') { $this->next(); return $this->truthy($this->notExpr()) ? 0 : -1; }
        return $this->cmpExpr();
    }

    private function cmpExpr(): mixed
    {
        $l = $this->addExpr();
        while (in_array($this->peek(), ['=', '<>', '<', '>', '<=', '>='], true)) {
            $op = $this->next();
            $r = $this->addExpr();
            $res = match ($op) {
                '=' => $l == $r, '<>' => $l != $r, '<' => $l < $r,
                '>' => $l > $r, '<=' => $l <= $r, '>=' => $l >= $r,
            };
            $l = $res ? -1 : 0;
        }
        return $l;
    }

    private function addExpr(): mixed
    {
        $l = $this->mulExpr();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->next();
            $r = $this->mulExpr();
            if ($op === '+' && (is_string($l) || is_string($r))) {
                if (!is_string($l) || !is_string($r)) throw new BasicError('Type mismatch');
                $l .= $r;
            } else {
                $this->num($l); $this->num($r);
                $l = $op === '+' ? $l + $r : $l - $r;
            }
        }
        return $l;
    }

    private function mulExpr(): mixed
    {
        $l = $this->powExpr();
        while (in_array($this->peek(), ['*', '/', 'MOD'], true)) {
            $op = $this->next();
            $r = $this->powExpr();
            $this->num($l); $this->num($r);
            if ($op !== '*' && $r == 0) throw new BasicError('Divisione per zero');
            $l = match ($op) { '*' => $l * $r, '/' => $l / $r, 'MOD' => fmod($l, $r) };
        }
        return $l;
    }

    private function powExpr(): mixed
    {
        $l = $this->unary();
        if ($this->peek() === '^') { $this->next(); $r = $this->powExpr(); $this->num($l); $this->num($r); $l = $l ** $r; }
        return $l;
    }

    private function unary(): mixed
    {
        if ($this->peek() === '-') { $this->next(); $v = $this->unary(); $this->num($v); return -$v; }
        if ($this->peek() === '+') { $this->next(); return $this->unary(); }
        return $this->primary();
    }

    private function primary(): mixed
    {
        $t = $this->next();
        if ($t === null) throw new BasicError('Espressione incompleta');
        if (is_int($t) || is_float($t)) return $t;
        if (str_starts_with($t, '"')) return substr($t, 1);
        if ($t === '(') { $v = $this->expr(); $this->expect(')'); return $v; }

        $fn = self::FUNCTIONS[$t] ?? null;
        if ($fn !== null) {
            $args = [];
            if ($fn[0] > 0 || $this->peek() === '(') {
                $this->expect('(');
                if ($this->peek() !== ')') {
                    do { $args[] = $this->expr(); } while ($this->peek() === ',' && $this->next());
                }
                $this->expect(')');
            }
            return $this->call($t, $args);
        }

        if (preg_match('/^[A-Z][A-Z0-9]*\$?$/', $t)) {
            return $this->m['vars'][$t] ?? (str_ends_with($t, '$') ? '' : 0);
        }
        throw new BasicError('Token inatteso: ' . $t);
    }

    /** nome => [numero minimo di argomenti] */
    private const FUNCTIONS = [
        'ABS' => [1], 'INT' => [1], 'SQR' => [1], 'SGN' => [1], 'RND' => [0],
        'LEN' => [1], 'VAL' => [1], 'ASC' => [1], 'STR$' => [1], 'CHR$' => [1],
        'LEFT$' => [2], 'RIGHT$' => [2], 'MID$' => [2], 'TIME' => [0],
    ];

    private function call(string $fn, array $a): mixed
    {
        return match ($fn) {
            'ABS'    => abs($a[0]),
            'INT'    => (int)floor($a[0]),
            'SQR'    => sqrt($a[0]),
            'SGN'    => $a[0] <=> 0,
            'RND'    => mt_rand() / mt_getrandmax(),
            'LEN'    => strlen($a[0]),
            'VAL'    => (float)$a[0] + 0,
            'ASC'    => ord($a[0]),
            'STR$'   => $this->fmt($a[0]),
            'CHR$'   => chr((int)$a[0]),
            'LEFT$'  => substr($a[0], 0, (int)$a[1]),
            'RIGHT$' => (int)$a[1] > 0 ? substr($a[0], -(int)$a[1]) : '',
            'MID$'   => isset($a[2]) ? substr($a[0], (int)$a[1] - 1, (int)$a[2]) : substr($a[0], (int)$a[1] - 1),
            'TIME'   => time(),
        };
    }

    // ---------------------------------------------------------------- helpers

    private function truthy(mixed $v): bool { return is_string($v) ? $v !== '' : $v != 0; }

    private function num(mixed $v): void { if (is_string($v)) throw new BasicError('Type mismatch'); }

    private function fmt(mixed $v): string
    {
        if (is_string($v)) return $v;
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) return (string)(int)$v;
        return (string)$v;
    }

    private function peek(): mixed { return $this->toks[$this->pos] ?? null; }

    private function next(): mixed { return $this->toks[$this->pos++] ?? null; }

    private function expect(string $tok): void
    {
        $t = $this->next();
        if ($t !== $tok) throw new BasicError("Atteso '$tok', trovato " . json_encode($t));
    }

    private function identifier(): string
    {
        $t = $this->next();
        if (!is_string($t) || !preg_match('/^[A-Z][A-Z0-9]*\$?$/', $t)) {
            throw new BasicError('Atteso nome di variabile, trovato ' . json_encode($t));
        }
        return $t;
    }

    private function lineTokens(int $li): array
    {
        return $this->tokens[$li] ??= self::tokenize($this->m['program'][$li][1]);
    }

    /** Stringhe => '"contenuto', numeri => int|float, il resto => stringa maiuscola. */
    public static function tokenize(string $src): array
    {
        $out = [];
        $i = 0;
        $n = strlen($src);
        while ($i < $n) {
            $c = $src[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '"') {
                $end = strpos($src, '"', $i + 1);
                if ($end === false) throw new BasicError('Stringa non terminata');
                $out[] = '"' . substr($src, $i + 1, $end - $i - 1);
                $i = $end + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
                preg_match('/\d*\.?\d+(?:[eE][-+]?\d+)?|\d+\.?/A', $src, $mm, 0, $i);
                $out[] = str_contains($mm[0], '.') || stripos($mm[0], 'e') !== false ? (float)$mm[0] : (int)$mm[0];
                $i += strlen($mm[0]);
                continue;
            }
            if (ctype_alpha($c)) {
                preg_match('/[A-Za-z][A-Za-z0-9]*\$?/A', $src, $mm, 0, $i);
                $word = strtoupper($mm[0]);
                $i += strlen($mm[0]);
                $out[] = $word;
                if ($word === 'REM') break;
                continue;
            }
            $two = substr($src, $i, 2);
            if (in_array($two, ['<=', '>=', '<>'], true)) { $out[] = $two; $i += 2; continue; }
            if (str_contains('+-*/^()=<>,;:?', $c)) { $out[] = $c; $i++; continue; }
            throw new BasicError("Carattere inatteso '$c'");
        }
        return $out;
    }
}
