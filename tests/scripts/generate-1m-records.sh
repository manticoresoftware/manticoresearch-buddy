#!/usr/bin/env bash

RANDOM=1024
id=0

for n in `seq 1 10`; do
  s="a$s";
done;

mysql -P9306 -h0 -e "create table t(f text, a int, b float, j json, m multi, s string)";

process_batch() {
  start_id=$1
  end_id=$2
  (
    echo -n "insert into t (id, f, a, b, j, m, s) values "
    for id in $(seq $start_id $end_id); do
      random_text=$(
        for i in {1..3}; do
          printf "\\$(printf '%03o' $((RANDOM % 26 + 65 + RANDOM % 2 * 32)))";
        done; echo
      )
      echo -n "($id, '$s',$RANDOM, 1.$((RANDOM % 1000)), '{\"a\": [$RANDOM,$RANDOM], \"b\": $RANDOM}', ($RANDOM,$RANDOM,$RANDOM), '$random_text')"
      [ $id != $end_id ] && echo -n ","
    done
    echo ";"
  ) | mysql -P9306 -h0
  echo -n .
}

for m in $(seq 1 700); do
  start_id=$((id + 1))
  id=$((id + 1500))
  process_batch $start_id $id
done
echo
