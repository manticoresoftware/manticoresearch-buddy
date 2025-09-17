# AlterDistributedTable Plugin

## Description
Enables alter for distributed tables, allowing changes to local and agent configurations.

## Examples
- `ALTER TABLE dist_table local = 'node1:9312';`
- `ALTER TABLE dist_table agent = 'node2:9312', local = 'node1:9312';`
- `ALTER TABLE dist_table local = 'node1:9312|node3:9312';` (multiple locals)
- ``alter table `dist` local='t1' agent='127.0.0.1:9312:remote_index' local='t3'';``
