#!/usr/bin/env bash

lcg_seed=1024
lcg_m=$((2**32))
lcg_a=1103515245
state_file=$(mktemp)

# Initialize state file with 0
echo 0 > "$state_file"

# Function to generate pseudo-random numbers.
lcg() {
  local lcg_c
  read -r lcg_c < "$state_file"
  lcg_seed=$(((lcg_a*lcg_seed+lcg_c)%lcg_m))
  echo $((lcg_seed % 32768))
  echo $((lcg_c+1)) > "$state_file"
}

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
          num=$(( id % i ))
          printf "\\$(printf '%03o' $((`lcg` % 26 + 65 + `lcg` % 2 * 32)))";
        done; echo
      )
      echo -n "($id, '$s',`lcg`, 1.$((`lcg` % 1000)), '{\"a\": [`lcg`,`lcg`], \"b\": `lcg`}', (`lcg`,`lcg`,`lcg`), '$random_text')"
      [ $id != $end_id ] && echo -n ","
    done
    echo ";"
  ) | mysql -P9306 -h0
  echo -n .
}

for m in $(seq 1 600); do
  start_id=$((id + 1))
  id=$((id + 1500))
  process_batch $start_id $id
done
echo
