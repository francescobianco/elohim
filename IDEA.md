# Elohim

**Elohim** è una macchina virtuale astratta che non esiste perché risiede stabilmente su un computer, ma perché **si propaga nel tempo**.

L'idea nasce dal limite dei classici hosting PHP condivisi: ambienti economici capaci di elaborare richieste HTTP e produrre risposte, ma normalmente incapaci di mantenere un processo standalone residente, un daemon, un worker permanente o un server in ascolto continuo.

Elohim trasforma questo limite nel proprio modello computazionale.

## Idea fondamentale

Una normale macchina virtuale può essere pensata come:

```text
VM = macchina + memoria + processo residente
```

Elohim invece è:

```text
VM = stato(t) + codice + regole di transizione
```

La sua esistenza è la successione:

```text
S0 → S1 → S2 → S3 → ...
```

Ogni stato contiene tutto ciò che serve per produrre il successivo.

Un nodo riceve lo stato della macchina, esegue un quantum di computazione, produce il nuovo stato e lo inoltra.

```text
S42 --Host A--> S43
S43 --Host B--> S44
S44 --Host C--> S45
```

La macchina virtuale non è Host A, Host B o Host C.

**Elohim è la continuità causale della sequenza degli stati.**

## Nessuna memoria permanente sul nodo

Un principio fondamentale è che il server che esegue Elohim deve poter essere completamente stateless.

Non sono necessari:

```text
database
filesystem persistente
sessione
processo residente
```

Lo stato viaggia con l'esecuzione.

Il nodo realizza essenzialmente una funzione:

```text
new_state = execute(old_state, input, quantum)
```

e, completato il quantum, inoltra `new_state`.

Il processo virtuale quindi non "vive sul server".

Vive nel flusso:

```text
STATE → execute → STATE → execute → STATE → ...
```

La macchina è, in un certo senso, **un oggetto temporale anziché spaziale**.

## HTTPS come bus

Il mezzo minimo di trasporto può essere HTTPS.

```text
Host A
   |
   | HTTPS
   v
Host B
   |
   | HTTPS
   v
Host C
```

HTTPS non rappresenta l'applicazione, ma soltanto il bus attraverso cui la macchina continua a propagarsi.

Il runtime potrebbe esporre un unico endpoint generico:

```text
POST /elohim
```

e ricevere qualcosa concettualmente simile a:

```text
continuation
code reference
machine state
input
generation
metadata
```

Il significato applicativo rimane dentro la macchina virtuale.

## Quantum di esecuzione

Ogni hop esegue un certo numero di istruzioni o un certo intervallo massimo di CPU.

```text
receive continuation
↓
execute N instructions
↓
capture state
↓
forward continuation
```

Il batching delle istruzioni evita che ogni singola istruzione richieda una nuova richiesta HTTP.

Il quantum diventa quindi l'equivalente del time slice di un sistema operativo.

## Parallelismo

L'ambiente web è già naturalmente concorrente.

Elohim potrebbe sfruttare questa proprietà permettendo a un processo di generare più continuazioni:

```text
             thread A
           /
process ──+── thread B
           \
             thread C
```

Ogni ramo può essere eseguito da richieste indipendenti e anche da host differenti.

Questo rende possibile sperimentare:

```text
virtual threads
actors
worker pools
fork/join
message passing
agent swarms
```

Il problema principale non è avviare il parallelismo, ma definire correttamente la semantica dello stato concorrente.

Il modello più naturale probabilmente non è la shared memory classica, ma **message passing + stato locale**.

## Identità della macchina

L'identità di Elohim non dovrebbe dipendere da:

```text
IP
hostname
PID
server
filesystem
```

ma dalla propria continuità.

Una possibile formalizzazione è:

```text
state N+1 = {
    machine_id,
    generation,
    previous_state_hash,
    machine_state,
    output
}
```

Quindi:

```text
identity ≠ location
identity ≠ host
identity ≠ OS process

identity = valid causal chain
```

Una VM potrebbe quindi avere concettualmente:

```text
Machine: 8f39...
Age: 4 years
Generations: 9,843,201
Hosts used: 127
Current execution node: transient
```

pur non essendo mai esistita quattro anni dentro una singola macchina.

## Ridondanza e più host

Un'esecuzione può propagarsi tra più hosting:

```text
A → B → A → B
```

oppure:

```text
A → C → B → D → A
```

Aggiungere un nodo significa semplicemente aggiungere un altro esecutore compatibile con il protocollo Elohim.

Il nodo non diventa proprietario del processo.

Dichiara soltanto:

```text
"I can execute a quantum."
```

Questa proprietà permette di pensare Elohim come un:

**distributed execution fabric**

oppure:

**stateless computational fabric**

## Idle e sonno

Un processo che non ha nulla da fare può entrare in idle.

La soluzione minima è propagarsi comunque periodicamente:

```text
sleep <= 10s
↓
next continuation
```

In questo modo il tempo massimo di risveglio è limitato.

Una soluzione migliore è usare richieste pendenti come interrupt virtuali.

```text
process
  ↓
WAIT(port, timeout=10s)
  ↓
HTTP request remains pending
```

Se arriva un input:

```text
input → wake immediately
```

altrimenti:

```text
timeout → propagate → wait again
```

La connessione HTTP diventa quindi temporaneamente il luogo in cui il processo "dorme".

Questo porta a una proprietà importante:

> Se nessuno conserva lo stato e non esiste più nessuna continuation o richiesta pendente che lo rappresenti, il processo non è idle: è morto.

Una Elohim viva deve quindi essere sempre:

```text
executing
waiting
or propagating
```

## I/O

L'I/O non dovrebbe essere hardcoded come semplice `stdin/stdout`.

Il programma dovrebbe poter dichiarare le proprie porte logiche.

Per esempio:

```text
input command: text
input sensor: json

output events: text
output result: json
```

Il runtime materializza queste porte come mailbox o stream virtuali.

Il programma vede:

```text
READ command
WRITE events
SEND pid message
RECV mailbox
```

mentre dall'esterno l'I/O può essere realizzato tramite HTTPS, long polling o altri adapter.

Il concetto centrale è:

> **il processo non possiede una connessione: possiede una mailbox.**

Le connessioni sono soltanto mezzi temporanei per raggiungerla.

## Attach

Dal PC dell'utente dovrebbe essere possibile fare qualcosa concettualmente simile a:

```text
elohim attach <machine-id>
```

L'utente non fa attach alla request PHP corrente.

Fa attach all'identità persistente della macchina.

Si potrebbe avere:

```text
elohim ps
elohim attach
elohim inspect
elohim pause
elohim resume
elohim step
elohim kill
```

Per esempio:

```text
$ elohim ps

ID        STATE      AGE       NODE
8f39      running    3d 12h    transient
91aa      waiting    18h       transient
```

La console potrebbe collegarsi alle porte dichiarate dal programma.

## Debugging

Il runtime potrebbe offrire controllo simile a un debugger:

```text
pause
resume
step
inspect memory
inspect globals
inspect threads
inspect mailbox
```

Questo è particolarmente importante perché Elohim non ha un processo OS stabile da osservare.

L'osservabilità deve quindi essere una proprietà della VM stessa.

## WebAssembly

Il principale formato binario di Elohim dovrebbe essere **WebAssembly**.

WASM è adatto perché offre:

```text
portabilità
sandbox
memory model definito
toolchain moderne
linguaggio host-independent
formato binario compatto
```

Un utente potrebbe fare:

```text
elohim run program.wasm
```

e ricevere un identificatore:

```text
Machine ID: 8f39...
State: running
```

Il problema tecnico più importante è la continuation.

WebAssembly standard non nasce necessariamente per essere fermato arbitrariamente, serializzato e ripreso su un'altra macchina.

Elohim dovrebbe quindi definire precisamente cosa costituisce uno stato migrabile:

```text
module reference
linear memory
globals
tables
execution state
virtual descriptors
mailboxes
pending events
generation
```

Il runtime deve essere progettato affinché tutto ciò che è necessario a riprendere l'esecuzione sia catturabile.

## BASIC

Accanto a WebAssembly, Elohim dovrebbe avere un secondo linguaggio nativo: **BASIC**.

La ragione non è nostalgia.

Elohim nasce come macchina deliberatamente piccola, con risorse limitate e capacità computazionali ridotte.

Il BASIC avrebbe lo stesso ruolo che aveva nei primi home computer:

```text
piccolo
immediato
interpretabile
comprensibile
facile da serializzare
facile da modificare
adatto a memoria limitata
```

Come nel Commodore 64, BASIC sarebbe il linguaggio immediatamente disponibile all'accensione della macchina.

Un programma Elohim potrebbe essere:

```basic
10 INPUT A
20 LET B = A * 2
30 PRINT B
40 SLEEP 10
50 GOTO 10
```

oppure:

```basic
10 RECV "COMMAND", C$
20 IF C$ = "PING" THEN SEND "OUT", "PONG"
30 SLEEP 10
40 GOTO 10
```

BASIC potrebbe avere primitive native legate direttamente al runtime:

```text
PRINT
INPUT
SEND
RECV
SLEEP
YIELD
SPAWN
TIME
```

WASM sarebbe quindi il linguaggio della software factory moderna.

BASIC sarebbe il **linguaggio macchina umano di Elohim**.

## Una macchina deliberatamente piccola

Elohim non dovrebbe cercare di simulare Linux.

Non dovrebbe necessariamente offrire:

```text
filesystem POSIX completo
socket arbitrari
processi UNIX
fork()
device reali
```

Dovrebbe esporre invece un set molto piccolo di primitive virtuali.

Per esempio:

```text
read(port)
write(port)
send(process, message)
recv()
spawn(program)
yield()
sleep()
time()
```

Questa limitazione è una proprietà, non un difetto.

Elohim potrebbe essere pensata come:

> **un home computer distribuito che non si trova da nessuna parte.**

## Nodi e capability

I nodi possono offrire capability differenti.

Per esempio:

```text
Node A:
    http
    filesystem

Node B:
    llm

Node C:
    database

Node D:
    browser
```

Il programma non accede direttamente alla macchina fisica.

Richiede una capability:

```text
HTTP.GET
LLM.COMPLETE
FS.READ
DB.QUERY
```

Il runtime può decidere su quale nodo eseguire il prossimo quantum in base alle capability richieste.

Quindi l'esecuzione può avvicinarsi alla risorsa.

## Agenti intelligenti

Elohim potrebbe essere particolarmente interessante come runtime per agenti.

Un agente non sarebbe:

```text
LLM = agent
```

ma:

```text
agent =
    process
  + state
  + goals
  + mailbox
  + capabilities
```

L'LLM sarebbe soltanto una capability:

```text
llm.complete(...)
```

come:

```text
http.fetch(...)
mail.send(...)
clock.wait(...)
```

Un agente può quindi vivere per mesi pur utilizzando un LLM solo quando deve ragionare.

Esempio:

```text
Agent
  ↓
receive event
  ↓
reason
  ↓
act
  ↓
update state
  ↓
wait
```

## Mobile agents

Uno degli aspetti più interessanti è la possibilità di invertire il modello tradizionale.

Normalmente:

```text
agent stays
data moves
```

Con Elohim:

```text
agent state + code moves
```

L'agente può essere materializzato sul nodo che espone la capability necessaria.

```text
agent
 ↓
node with Git capability
 ↓
node with LLM capability
 ↓
node with database capability
```

Questo richiama i vecchi concetti di mobile agents, ma con tecnologie moderne come WASM, HTTPS e sandbox.

## Sciami

Un agente può inoltre creare altri processi:

```text
coordinator
   ├── researcher
   ├── coder
   ├── verifier
   └── critic
```

Ogni processo può:

```text
migrare
dormire
ricevere messaggi
generare altri processi
morire
```

Il fabric fornisce quindi una base naturale per sistemi multi-agent.

## Differenza rispetto a uno swarm tradizionale

Un cluster tradizionale fa:

```text
process
  ↓
container
  ↓
node
```

e, se il nodo muore:

```text
orchestrator → restart elsewhere
```

Elohim invece cerca di ottenere:

```text
process ≠ machine
```

Il nodo è solo il luogo temporaneo in cui una porzione della vita del processo viene materializzata.

Uno swarm tradizionale ha enormi vantaggi:

```text
performance
maturity
observability
simplicity
persistent connections
caching
existing tooling
```

Elohim ha senso solo se preserva proprietà che lo swarm non possiede naturalmente:

```text
no home node
stateless executors
portable continuation
identity independent from infrastructure
execution opportunism
survival without permanent residency
```

La domanda critica per il progetto è quindi:

> Se togliamo il vincolo degli hosting PHP economici, rimane una proprietà computazionale che vale la pena preservare?

La risposta candidata è:

> **la computazione può avere continuità senza avere residenza.**

## Privacy e opacità

HTTPS può rendere opaco il traffico verso osservatori esterni, ma non rende automaticamente invisibile l'esecuzione al proprietario dell'host.

Un nodo che esegue normalmente il programma può potenzialmente vedere:

```text
memory
code
runtime state
CPU usage
network activity
```

Uno stato cifrato protegge il contenuto a riposo, ma durante l'esecuzione deve normalmente essere decifrato.

Per rendere l'esecuzione realmente opaca anche al nodo servirebbero tecnologie ulteriori, ad esempio confidential computing o trusted execution environments.

Questa proprietà va quindi tenuta distinta dal concetto fondamentale di Elohim.

## Definizione compatta

Elohim può essere descritto come:

> **Una macchina virtuale aperta, distribuita e stateless, la cui identità non risiede in una macchina fisica ma nella continuità verificabile del proprio stato attraverso il tempo. I nodi le prestano temporaneamente capacità di esecuzione; codice, memoria e continuation si propagano tra essi. WebAssembly ne costituisce il formato binario portabile, mentre BASIC ne rappresenta il linguaggio nativo minimale e interattivo.**

Oppure, ancora più sinteticamente:

> **Elohim è una macchina che non vive da nessuna parte: continua a esistere perché continua a propagarsi.**

## Immagine mentale finale

```text
                ELOHIM

        code + state + identity
                  │
                  ▼
          ┌─────────────┐
          │   Node A    │
          │ execute q1  │
          └──────┬──────┘
                 │
              HTTPS
                 │
                 ▼
          ┌─────────────┐
          │   Node B    │
          │ execute q2  │
          └──────┬──────┘
                 │
              HTTPS
                 │
                 ▼
          ┌─────────────┐
          │   Node C    │
          │ execute q3  │
          └──────┬──────┘
                 │
                ...

        S0 → S1 → S2 → S3 → ...

      the machine is the continuity
             of this sequence
```

Elohim non è quindi un server.

Non è un container.

Non è un processo Unix.

Non è un cluster.

È **uno spazio computazionale virtuale che prende forma solo quando qualcuno gli presta, per un istante, una macchina reale**.