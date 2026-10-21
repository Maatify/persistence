#!/usr/bin/env bash
# Independent assertions over results/*.txt: every expected RED (shipped behavior) and GREEN (prototype) outcome is checked
# explicitly, per engine. A missing line or a non-matching line is a failure.
set -euo pipefail
cd "$(dirname "$0")/results"
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1" >&2; fail=1; }

ALL="mysql-8.0.46 mysql-8.4.10 mysql-8.4.10-rollback-on-timeout mariadb-11.8.9"
DEF="mysql-8.0.46 mysql-8.4.10 mariadb-11.8.9"        # innodb_rollback_on_timeout=OFF
MYSQL="mysql-8.0.46 mysql-8.4.10 mysql-8.4.10-rollback-on-timeout"
MARIA="mariadb-11.8.9"
ROT="mysql-8.4.10-rollback-on-timeout"

# line <file> <engine> <cell-id-prefix> : the result line of that cell in that engine's block
line() { awk -v e="#### $2" -v c="$3" '$0==e{on=1;next} /^#### /{on=0} on && index($0,c)==1{print; exit}' "$1"; }
# expect <label> <file> "<engines>" <cell> <ERE>
expect() {
  local label="$1" file="$2" engines="$3" cell="$4" re="$5" e l
  for e in $engines; do
    l=$(line "$file" "$e" "$cell")
    if [[ -n "$l" && "$l" =~ $re ]]; then ok "$label [$e]"; else bad "$label [$e] :: ${l:-<no line>}"; fi
  done
}
PERM_BAD='PARTIAL\[1:1 2:3 3:4 4:5 5:5 CORRUPT\]'

# ---------- RED: the shipped manager inside a caller-owned transaction ----------
R=matrix-real.txt
expect "RED controls: success"                $R "$ALL" C01-ok       'true +\| ownView=MOVED marker=1 txn=alive'
expect "RED controls: no-op"                  $R "$ALL" C02-noop     'true +\| ownView=ORIGINAL'
expect "RED controls: missing row -> false before any shift, clean" $R "$ALL" C03-missing 'false +\| ownView=ORIGINAL .*afterCommit=ORIGINAL'
expect "RED false after shifts: partial shifts committed by the caller's commit" $R "$ALL" C04-false "false +\| ownView=$PERM_BAD .*afterCommit=$PERM_BAD"
expect "RED 1062 in the range shift: statement rolled back, no partial" $R "$ALL" C05-1062shift 'EXC PDO 1062/23000 +\| ownView=ORIGINAL .*txn=alive'
expect "RED 1062 on the target write: partial shifts committed" $R "$ALL" C06-1062final "EXC PDO 1062/23000 +\| ownView=$PERM_BAD .*afterCommit=$PERM_BAD"
expect "RED 1205 on the target write (rollback_on_timeout=OFF): partial shifts committed" $R "$DEF" C07-1205final "EXC PDO 1205/HY000 +\| ownView=$PERM_BAD .*txn=alive .*afterCommit=$PERM_BAD"
expect "RED 1205 in the range shift: no partial" $R "$DEF" C08-1205shift 'EXC PDO 1205/HY000 +\| ownView=ORIGINAL .*txn=alive'
expect "RED 1205 with rollback_on_timeout=ON: whole transaction lost" $R "$ROT" C07-1205final 'EXC PDO 1205/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST'
expect "RED 1213: whole transaction lost, earlier work gone" $R "$ALL" C10-1213final 'EXC PDO 1213/40001 +\| ownView=ORIGINAL marker=0 txn=LOST .*partner=ACQUIRED'
expect "RED 1020 (MariaDB, scope-lock phase): whole transaction lost" $R "$MARIA" C11-1020lock 'EXC PDO 1020/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST'
expect "RED 1020 (MariaDB, target-write phase): whole transaction lost" $R "$MARIA" C12-1020final 'EXC PDO 1020/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST'
expect "RED two operations: the failing 2nd one leaves partial shifts on top of the 1st" $R "$ALL" C13-two-steps "true,EXC PDO 1062/23000 +\| ownView=PARTIAL.*CORRUPT"
expect "RED carry-on-after-1213: later statements autocommit, partial shifts committed" $R "$ALL" C15-after-1213 "EXC PDO 1213/40001,EXC PDO 1062/23000 .*afterCommit=PARTIAL.*CORRUPT"
expect "package-owned (control): success"     $R "$ALL" O1-ok        'true +\| ownView=MOVED .*Δcommit/rollback=1/0'
expect "package-owned (control): false rolls back, clean" $R "$ALL" O2-false 'false +\| ownView=ORIGINAL .*Δcommit/rollback=0/1'
expect "package-owned (control): 1062 rolls back, clean" $R "$ALL" O3-1062final 'EXC PDO 1062/23000 +\| ownView=ORIGINAL .*Δcommit/rollback=0/1'
# the shipped call issues no savepoint statement at all
[[ $(grep -c 'Δsp/rt/rel=0/0/0' $R) -eq $(grep -cE '^(C|O)[0-9]+-' $R) ]] && ok "shipped call issues no savepoint statements (every cell)" || bad "shipped call issued savepoint statements"

# ---------- GREEN: prototype (recommended design) ----------
G=matrix-proto.txt
expect "GREEN success: one SAVEPOINT + one RELEASE" $G "$ALL" C01-ok 'true +\| ownView=MOVED marker=1 txn=alive .*Δsp/rt/rel=1/0/1'
expect "GREEN no-op: no savepoint statement"  $G "$ALL" C02-noop    'true .*Δsp/rt/rel=0/0/0'
expect "GREEN missing row: false, no savepoint statement" $G "$ALL" C03-missing 'false +\| ownView=ORIGINAL .*Δsp/rt/rel=0/0/0 .*afterCommit=ORIGINAL'
expect "GREEN false after shifts: shifts undone, still false, transaction alive" $G "$ALL" C04-false 'false +\| ownView=ORIGINAL marker=1 txn=alive .*Δsp/rt/rel=1/1/1 .*afterCommit=ORIGINAL marker=1'
expect "GREEN 1062 in the shift: original exception, clean" $G "$ALL" C05-1062shift 'EXC PDO 1062/23000 +\| ownView=ORIGINAL marker=1 txn=alive .*Δsp/rt/rel=1/1/1'
expect "GREEN 1062 on the target write: original exception, shifts undone" $G "$ALL" C06-1062final 'EXC PDO 1062/23000 +\| ownView=ORIGINAL marker=1 txn=alive .*afterCommit=ORIGINAL marker=1'
expect "GREEN 1205 on the target write: shifts undone, transaction alive" $G "$DEF" C07-1205final 'EXC PDO 1205/HY000 +\| ownView=ORIGINAL marker=1 txn=alive .*afterCommit=ORIGINAL marker=1'
expect "GREEN 1205 in the shift: clean" $G "$DEF" C08-1205shift 'EXC PDO 1205/HY000 +\| ownView=ORIGINAL marker=1 txn=alive'
expect "NOT RESCUABLE 1205 with rollback_on_timeout=ON: transaction lost, ROLLBACK TO fails, original 1205 propagates" $G "$ROT" C07-1205final 'EXC PDO 1205/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST .*Δsp/rt/rel=1/1/0'
expect "NOT RESCUABLE 1213: transaction lost, ROLLBACK TO fails, original 1213 propagates" $G "$ALL" C10-1213final 'EXC PDO 1213/40001 +\| ownView=ORIGINAL marker=0 txn=LOST .*Δsp/rt/rel=1/1/0'
expect "NOT RESCUABLE 1020 in the scope-lock phase: no savepoint statement issued yet" $G "$MARIA" C11-1020lock 'EXC PDO 1020/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST .*Δsp/rt/rel=0/0/0'
expect "NOT RESCUABLE 1020 in the mutation window: ROLLBACK TO fails, original 1020 propagates" $G "$MARIA" C12-1020final 'EXC PDO 1020/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST .*Δsp/rt/rel=1/1/0'
expect "GREEN 1020 scenarios are controls on MySQL (current read, success)" $G "$MYSQL" C12-1020final 'true +\| ownView=MOVED'
expect "GREEN operation-local: the 1st move is kept, only the failing 2nd is undone" $G "$ALL" C13-two-steps 'true,EXC PDO 1062/23000 +\| ownView=MOVED marker=1 .*Δsp/rt/rel=2/1/2 .*afterCommit=MOVED marker=1'
expect "GREEN caller's own savepoint stays usable" $G "$ALL" C14-caller-sp 'ownView=ORIGINAL marker=1 .*callerSavepointUsable=yes'
expect "GREEN carry-on-after-1213: fails closed, nothing autocommitted" $G "$ALL" C15-after-1213 'EXC PDO 1213/40001,EXC TransactionExecutionException .*afterCommit=ORIGINAL marker=0'
expect "GREEN SAVEPOINT returns false: TransactionExecutionException, nothing mutated" $G "$ALL" S1-sp-false 'EXC TransactionExecutionException +\| ownView=ORIGINAL marker=1 txn=alive .*Δsp/rt/rel=0/0/0'
expect "GREEN SAVEPOINT throws: same Throwable object" $G "$ALL" S2-sp-throws 'ownView=ORIGINAL marker=1 .*origIdentity=kept'
expect "RESIDUAL 1062 + ROLLBACK TO fails: original 1062 wins; partial remains for the caller to roll back" $G "$ALL" S3-1062+rt-false "EXC PDO 1062/23000 +\| ownView=$PERM_BAD .*txn=alive"
expect "RESIDUAL original Throwable + ROLLBACK TO throws: original object wins" $G "$ALL" S4-orig+rt-throws "EXC RuntimeException +\| ownView=$PERM_BAD .*origIdentity=kept"
expect "GREEN false + ROLLBACK TO fails: fails closed (never reports false over partial shifts)" $G "$ALL" S5-false+rt-false "EXC TransactionExecutionException +\| ownView=$PERM_BAD"
expect "GREEN RELEASE returns false: undone, TransactionExecutionException" $G "$ALL" S6-rel-false 'EXC TransactionExecutionException +\| ownView=ORIGINAL marker=1 .*Δsp/rt/rel=1/1/0 .*afterCommit=ORIGINAL'
expect "GREEN RELEASE throws: undone, same Throwable object" $G "$ALL" S7-rel-throws 'ownView=ORIGINAL marker=1 .*origIdentity=kept'
expect "GREEN package-owned path identical to shipped: success" $G "$ALL" O1-ok 'true +\| ownView=MOVED .*Δsp/rt/rel=0/0/0 Δcommit/rollback=1/0'
expect "GREEN package-owned path identical to shipped: false" $G "$ALL" O2-false 'false +\| ownView=ORIGINAL .*Δcommit/rollback=0/1'
expect "GREEN package-owned path identical to shipped: 1062" $G "$ALL" O3-1062final 'EXC PDO 1062/23000 +\| ownView=ORIGINAL .*Δcommit/rollback=0/1'
# Ownership: no commit or full rollback issued by any caller-owned cell of the prototype
[[ $(grep -E '^(C|S)[0-9]+-' $G | grep -vc 'Δcommit/rollback=0/0') -eq 0 ]] && ok "ownership: prototype never commits or fully rolls back a caller-owned transaction (every C/S cell)" || bad "prototype issued COMMIT/ROLLBACK in a caller-owned cell"

# ---------- consumer recipe (no Runtime change) ----------
C=matrix-recipe.txt
expect "RECIPE false after shifts: undone (consumer throws inside the callback)" $C "$ALL" C04-false 'false +\| ownView=ORIGINAL marker=1 txn=alive'
expect "RECIPE gap: false is reported over PARTIAL shifts when the undo fails" $C "$ALL" S5-false+rt-false "false +\| ownView=$PERM_BAD"
expect "RECIPE eager savepoint: no-op move still issues SAVEPOINT/RELEASE" $C "$ALL" C02-noop 'Δsp/rt/rel=1/0/1'
expect "RECIPE eager savepoint: missing row rolls back to the savepoint" $C "$ALL" C03-missing 'false .*Δsp/rt/rel=1/1/1'
expect "RECIPE 1213 not rescuable" $C "$ALL" C10-1213final 'EXC PDO 1213/40001 +\| ownView=ORIGINAL marker=0 txn=LOST'
expect "RECIPE 1020 not rescuable (MariaDB)" $C "$MARIA" C11-1020lock 'EXC PDO 1020/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST .*Δsp/rt/rel=1/1/0'


# ---------- released v1.4.0 (Addendum A1): the same RED, from the published tag's source ----------
V=matrix-real-v1.4.0.txt
[[ $(grep -c '^source: .*v140/src/Pdo/Ordering/ScopedOrderingManager.php' $V) -eq 4 ]] && ok "v1.4.0 matrix ran against the v1.4.0 source tree on all four engines" || bad "v1.4.0 source path"
expect "v1.4.0 RED false after shifts"        $V "$ALL" C04-false "false +\| ownView=$PERM_BAD .*afterCommit=$PERM_BAD"
expect "v1.4.0 RED 1062 on the target write"  $V "$ALL" C06-1062final "EXC PDO 1062/23000 +\| ownView=$PERM_BAD .*afterCommit=$PERM_BAD"
expect "v1.4.0 RED 1205 on the target write"  $V "$DEF" C07-1205final "EXC PDO 1205/HY000 +\| ownView=$PERM_BAD .*afterCommit=$PERM_BAD"
expect "v1.4.0 RED two operations"            $V "$ALL" C13-two-steps "true,EXC PDO 1062/23000 +\| ownView=PARTIAL.*CORRUPT"
expect "v1.4.0 RED carry on after 1213"       $V "$ALL" C15-after-1213 "EXC PDO 1213/40001,EXC PDO 1062/23000 .*afterCommit=PARTIAL.*CORRUPT"
expect "v1.4.0 safe: missing row / 1062 in the shift" $V "$ALL" C05-1062shift 'EXC PDO 1062/23000 +\| ownView=ORIGINAL'
expect "v1.4.0 not rescuable 1213 (same as main)" $V "$ALL" C10-1213final 'EXC PDO 1213/40001 +\| ownView=ORIGINAL marker=0 txn=LOST'
expect "v1.4.0 MariaDB 1020 (pre-#33 scope lock)" $V "$MARIA" C11-1020lock 'EXC PDO 1020/HY000 +\| ownView=ORIGINAL marker=0 txn=LOST'

# ---------- reuse of the existing savepoint runner for the mutation window (Addendum A3) ----------
U=matrix-reuse.txt
expect "REUSE success / 1062 / false are undone like the inline design" $U "$ALL" C04-false 'false +\| ownView=ORIGINAL marker=1 txn=alive'
expect "REUSE 1062 on the target write undone" $U "$ALL" C06-1062final 'EXC PDO 1062/23000 +\| ownView=ORIGINAL marker=1 txn=alive'
expect "REUSE GAP 1: false is reported over PARTIAL shifts when the undo fails" $U "$ALL" S5-false+rt-false "false +\| ownView=$PERM_BAD"
expect "REUSE GAP 2: after a swallowed 1213 the runner opens its own transaction inside a participating call" $U "$ALL" C15-after-1213 'EXC PDO 1213/40001,EXC PDO 1062/23000 .*Δcommit/rollback=0/1'
expect "INLINE: the same two cells fail closed and never begin a transaction" $G "$ALL" C15-after-1213 'EXC TransactionExecutionException .*Δcommit/rollback=0/0'

# ---------- prototype inside a consumer's savepoint runner ----------
N=matrix-nested.txt
expect "NESTED savepoints: 1062 undone, both savepoints cleaned up" $N "$ALL" C06-1062final 'EXC PDO 1062/23000 +\| ownView=ORIGINAL marker=1 txn=alive .*Δsp/rt/rel=2/2/2'
expect "NESTED savepoints: success releases both" $N "$ALL" C01-ok 'true .*Δsp/rt/rel=2/0/2'
expect "NESTED savepoints: 1st move kept, 2nd undone" $N "$ALL" C13-two-steps 'true,EXC PDO 1062/23000 +\| ownView=MOVED'

# ---------- differential parity ----------
D=results
S4=s4-differential.txt
[[ $(grep -c 'mismatches=0' $S4) -eq 16 && $(grep -c 'mismatches=[1-9]' $S4) -eq 0 ]] && ok "S4 differential parity: 0 mismatches over 4 shapes x 4 engines (400 ops each)" || bad "S4 differential parity"
[[ $(grep -c 'package-owned .*prototypeSavepointStatements=0' $S4) -eq 8 ]] && ok "S4 package-owned path issues no savepoint statement" || bad "S4 owned savepoint count"

# ---------- locks ----------
S5=s5-locks.txt
[[ $(grep -c 'L1 .*row 6 is free' $S5) -eq 12 ]] && ok "S5 L1 ROLLBACK TO SAVEPOINT releases locks taken after the savepoint (3 statement kinds x 4 engines)" || bad "S5 L1"
[[ $(grep -c 'L2 proto .*rows 1,3,5 are locked(1205)/locked(1205)/locked(1205)' $S5) -eq 4 && $(grep -c 'L2 real .*rows 1,3,5 are locked(1205)/locked(1205)/locked(1205)' $S5) -eq 4 ]] && ok "S5 L2 lazy savepoint keeps the scope locks after a failed move, like the shipped call" || bad "S5 L2 proto/real"
[[ $(grep -c 'L2 recipe .*rows 1,3,5 are free/free/free' $S5) -eq 4 ]] && ok "S5 L2 eager (consumer) savepoint RELEASES the scope locks on failure" || bad "S5 L2 recipe"
[[ $(grep -c 'L3 .*rows 1,3,5 are locked(1205)/locked(1205)/locked(1205)' $S5) -eq 12 ]] && ok "S5 L3 after a successful move the scope stays locked for all three subjects" || bad "S5 L3"
exit $fail
