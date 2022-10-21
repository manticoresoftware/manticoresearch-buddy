# Manticore Buddy
Manticore Buddy is a Manticore Search's sidecar written in PHP which helps it with various tasks. The typical workflow is that before returning an error to the user Manticore Search asks Buddy if it can handle it for the daemon. Buddy is written in PHP which makes it easy to implement high-level functionalities that do not require extra-high performance.

## SQL processing

### Introduction

To control different SQL commands we use the following architecture. Each command consists of a prefix and the rest of parameters. For example, we have "backup to local(path)". `backup` is the command in that case and `to local(path) is the rest.

### The flow

Let's start with an example and create abstract `RESTORE` command!

> You should look `CommandExecutorInterface` and `CommandRequestInterface` first to understand base requirements

1. We implement `RestoreRequest` class that implements `CommandRequestInterface`.
2. We write code that implements `fromQuery` method the purpose of which is to parse the string of the **rest** and return the self but with parsed arguments.
3. We implement `RestoreExecutor` class that implements `CommandExecutorInterface` and the purpose of it is to process the command by using the parsed request.
4. We write code that implements `run` method and to the job by returning the instance of the `Task`.
5. We can use that instance to check the status, get the result and also get full control of async execution!
6. Done!

### Debug

To debug the flow you can use `bin/query` script. To run it just pass the query and it will do the job and print some results. For example:

```bash
  $ bin/query "BACKUP"
  Running query: BACKUP


  Manticore config
    endpoint =  127.0.0.1:9308

  Manticore versions:
    manticore: 5.0.3 129438c1c@221013 dev
    columnar: 0.0.0
    secondary: 0.0.0
  2022-10-20 14:26:51 [Info] Starting the backup...
  Status code: Finished
  Result: Backup directory is not writable
  done
```
