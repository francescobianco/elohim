# elohim

Prototipo locale di [Elohim](IDEA.md): una macchina virtuale BASIC che non risiede su nessun nodo,
ma si propaga tra tre esecutori PHP stateless (`node-a`, `node-b`, `node-c`) tramite `POST elohim.php`.

## Build

```sh
./build          # → dist/elohim.php (standalone, nessuna dipendenza) + dist/elohim.txt
```

`src/Basic.php` e `src/node.php` vengono fusi in un unico file `dist/elohim.php`.

## Installazione su hosting (FTP)

Copia nella stessa cartella di ogni hosting:

- `dist/elohim.php` — il nodo, l'unico entry point (niente mod_rewrite né `.htaccess`)
- `elohim.txt` — l'elenco di **tutti** i nodi, un URL per riga (vedi `elohim.example.txt`)

```text
# elohim.txt
https://www.hosting-uno.it/elohim.php
https://hosting-due.com/tools/elohim.php
```

Ogni nodo si riconosce nell'elenco confrontando il proprio URL e inoltra la macchina al successivo
(l'ultimo torna al primo). Aggiungere un nodo = caricare `elohim.php` sul nuovo host e
aggiungere la riga in `elohim.txt` su tutti. `GET elohim.php` mostra lo stato del nodo
(`in_ring` indica se si è trovato nell'elenco). Requisiti: PHP 8.1+, chiamate HTTP in uscita
(curl oppure `allow_url_fopen`). I log finiscono nell'error log PHP dell'hosting.

Per avviare un programma su un hosting reale:

```sh
ELOHIM_NODE=https://www.hosting-uno.it/elohim.php ./elohim run examples/hello.bas
```

## Deploy multi-host (lftp)

Ogni hosting è un file `hosts/<nome>.env` (vedi `hosts/example.env.dist`); il contenuto di `hosts/`
è ignorato da git perché contiene le credenziali FTP.

```sh
./deploy list               # host configurati
./deploy push [host...]     # build, genera dist/elohim.txt da tutti gli URL, upload via FTP
./deploy check [host...]    # verifica che ogni nodo risponda e si trovi nel ring
```

`elohim.txt` viene generato dagli `URL` di **tutti** gli host, anche quando si fa push solo su alcuni.

## Test locale con docker

Tre container `php:8.3-apache` non modificati montano solo `dist/elohim.php` e
`docker/elohim.txt`, come se fossero tre hosting riempiti via FTP.

```sh
./build
docker compose up -d
./elohim nodes                              # "I can execute a quantum."
./elohim run examples/hello.bas             # avvia una macchina: S0 → S1 → ...
./elohim run examples/double.bas 21 5 0     # gli argomenti riempiono la mailbox di INPUT
./elohim logs                               # segue la macchina mentre salta tra i nodi
```

I nodi sono esposti su `localhost:7081-7083`.

## Come funziona

Ogni nodo riceve una continuation (JSON), risponde subito `202`, esegue un quantum
(`QUANTUM` istruzioni, default 20), produce il nuovo stato e lo inoltra al nodo successivo
nell'anello definito da `elohim.txt`. Se un peer non risponde, prova il successivo.

```text
{ machine_id, generation, previous_state_hash, hosts, machine, output, hash }
```

- `hash` è lo sha256 dello stato; il nodo ricevente lo verifica (catena causale).
- `SLEEP n` mette la macchina in `sleeping`: il nodo successivo tiene la request aperta
  fino al risveglio (max 10s per hop), poi riprende o si propaga di nuovo.
- `END`, la fine del programma o un errore fermano la propagazione: la macchina muore.

## BASIC supportato

`PRINT` `INPUT` `LET` `IF … THEN` `GOTO` `GOSUB`/`RETURN` `FOR … TO … STEP`/`NEXT`
`SLEEP` `YIELD` `SEND "porta", …` `END` `REM`, più statement multipli con `:`.

Funzioni: `ABS INT SQR SGN RND LEN VAL ASC STR$ CHR$ LEFT$ RIGHT$ MID$ TIME`.
