#!/usr/bin/env bash
# Independent assertions over results/*.txt. Each expected RED and GREEN outcome is checked explicitly.
set -euo pipefail
cd "$(dirname "$0")/results"
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1" >&2; fail=1; }
# block <file> <header-regex>: lines of the first '== ' section whose header matches
block() { awk -v re="$2" '/^== /{p=($0 ~ re)} p' "$1"; }
blocks() { awk -v re="$2" '/^== /{p=($0 ~ re)} p' "$1"; } # all matching sections
count() { grep -c -E "$2" <<<"$1" || true; }

# P1: RED where snapshot isolation is ON, GREEN elsewhere, owned control always GREEN
for hdr in 'MariaDB.*snapshot_isolation=1$'; do
  b=$(blocks p1-reproduce.txt "$hdr")
  [[ $(count "$b" 'getNextPositionForUpdate +ERR 1020') -eq 4 ]] && ok "P1 getNextPositionForUpdate RED on 10.11/11.4/11.8 with ON (11.8 default+ON)" || bad "P1 getNextPositionForUpdate RED count"
  [[ $(count "$b" 'moveWithinScope\(1->5\) +ERR 1020') -eq 4 ]] && ok "P1 moveWithinScope RED on 10.11/11.4/11.8 with ON (11.8 default+ON)" || bad "P1 moveWithinScope RED count"
done
g=$(blocks p1-reproduce.txt 'snapshot_isolation=(0|\(absent\))$')
[[ $(count "$g" 'ERR') -eq 0 ]] && ok "P1 GREEN with OFF/default-OFF and on MySQL (no ERR)" || bad "P1 unexpected ERR where setting is OFF/absent"
[[ $(count "$(cat p1-reproduce.txt)" 'owned txn, no prior snapshot' ) -ge 10 && $(count "$(cat p1-reproduce.txt)" 'ok: true +\(owned') -eq $(count "$(cat p1-reproduce.txt)" 'owned txn') ]] && ok "P1 owned control GREEN everywhere" || bad "P1 owned control"
# P1 on released v1.4.0: moveWithinScope RED only on 11.8.9 default
v=$(block p1-reproduce-v1.4.0.txt '11\.8\.9')
grep -qE 'moveWithinScope\(1->5\) +ERR 1020' <<<"$v" && ok "v1.4.0 moveWithinScope RED on 11.8.9" || bad "v1.4.0 not RED on 11.8.9"
[[ $(grep -c 'ERR' p1-reproduce-v1.4.0.txt) -eq 1 ]] && ok "v1.4.0 exactly one ERR (11.8.9 default)" || bad "v1.4.0 errors elsewhere"
# P2: MySQL never errors; MariaDB 11.8.9 errors in the tail FOR UPDATE row but not in the 'no change' column
[[ $(count "$(block p2-root-cause.txt '8\.4\.10')" 'ERR') -eq 0 ]] && ok "P2 MySQL 8.4.10 no ERR in matrix" || bad "P2 MySQL errors"
tailrow=$(block p2-root-cause.txt '11\.8\.9' | grep '^FOR UPDATE tail')
[[ "$tailrow" == *"ERR 1020"*"ERR 1020"*"ERR 1020"*"ok"*"ok"* ]] && ok "P2 tail FOR UPDATE: 1020 x3 then ok x2 on 11.8.9" || bad "P2 tail row shape"
# P3: invariant = earlier work lost on every MariaDB ON engine; transaction-state assertions deliberately absent
[[ $(count "$(blocks p3-error-fate.txt 'MariaDB.*snapshot_isolation=1$')" 'own-view marker rows=0') -ge 6 ]] && ok "P3 earlier work lost on all MariaDB ON engines" || bad "P3 earlier-work-lost"
[[ $(count "$(blocks p3-error-fate.txt 'MariaDB.*snapshot_isolation=0$|MySQL|^== P3 8')" 'own-view marker rows=1') -ge 6 ]] && ok "P3 earlier work kept where no 1020" || bad "P3 control"
# P4: 1020 propagates unchanged and is not replayed
grep -q 'attempts=1 ' <(block p4-propagation.txt '11\.8\.9') && ok "P4 deadlock-retry runner does not replay 1020" || bad "P4 replay"
# P5: remedies R1-R4 GREEN, baseline RED, R6 restart GREEN on 11.8.9
p5=$(block p5-remedies.txt '11\.8\.9')
[[ $(count "$p5" '^  none \(baseline\).*ERR 1020') -eq 2 ]] && ok "P5 baseline RED" || bad "P5 baseline"
[[ $(count "$p5" '^  R[1-4] .*ok:') -eq 8 ]] && ok "P5 R1-R4 GREEN" || bad "P5 R1-R4"
grep -q 'first=ERR 1020, restarted=ok: 6' <<<"$p5" && ok "P5 R6 restart GREEN" || bad "P5 R6"
# P7: SET STATEMENT removes 1020 on MariaDB and is a syntax error on MySQL
p7=$(block p7-statement-scope.txt '11\.8\.9')
[[ $(count "$p7" 'SET STATEMENT.*ok:') -eq 2 && $(count "$p7" 'plain statement +-> ERR 1020') -eq 2 ]] && ok "P7 statement-scope viability" || bad "P7"
[[ $(count "$(block p7-statement-scope.txt '8\.4\.10')" 'ERR 1064') -eq 2 ]] && ok "P7 MySQL rejects syntax" || bad "P7 MySQL"
exit $fail
