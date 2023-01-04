# Manticore Buddy

Manticore Buddy is a Manticore Search's sidecar written in PHP, which helps it with various tasks. Buddy is written in PHP, which makes it easy to implement high-level functionalities that do not require extra-high performance. The typical workflow is that before returning an error to the user, Manticore Search asks Buddy if it can handle it for the daemon.

## SQL processing

### Introduction

To control different SQL commands, we use the following architecture. Each command consists of a prefix and the rest of the parameters. For example, we have "backup to /path/to/folder" `backup` is the command in that case, and `to local(path) is the rest.

### The flow

Each request is handled by ReactPHP, parsed in the main loop, and forwarded to the `QueryProcessor`, the main entry point of detection, which command we will process next.

Each command that Buddy can process consists of the implementation of two interfaces:

1. CommandRequestInterface

2. CommandExecutorInterface

**CommandRequestInterface** represents parsing the raw network data from JSON and adopting it to future request processing by the command. So the primary purpose is to parse into **CommandRequest** and throw exceptions in case of any error.

**CommandExecutorInterface** does all math and contains the logic of the command that must be executed by using `Task` class that is run in parallel in a separate thread to make it nonblocking.

You can throw any exception when implementing the new command because they are all caught in a loop and processed.

There is `GenericError` that implements `setResponseError` and `getResponseError`. In case you want to provide a user-friendly error, you should use `setResponseError` in addition or create an exception with `GenericError::create('User-friendly error goes here')`. This is important to understand that the default exception message will not go to the user response but to the Manticore log file.

### Steps example of command creation

Let's start with an example and create abstract `RESTORE` command!

1. We create a directory with our command namespace `src/Restore` and implement `Request` and `Executor` classes.

2. We write code that implements `fromNetworkRequest` in `Request`, which is to parse the input Network request and return the new `Request` instance with all data loaded in it to use it in `Executor`.

3. We write code that implements the `run` method and does all logic by returning an instance of the `Task` class.

4. We can use `Task` instance to check the status, get the result, and get complete control of async execution!

5. As a final step, we add a case for our new command to the method `extractCommandFromRequest` of the `QueryProcessor` class.

### Debug

To debug the flow, you can use `bin/query` script. To run it, pass the query, which will do the job and print some results. For example:

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

### Running from CLI

To run a Buddy instance from CLI, use the following command:

```bash
  $ manticore-executor src/main.php [ARGUMENTS]
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  Usage: manticore-executor src/main.php [ARGUMENTS]

  Arguments are:
  --listen               HTTP endpoint to accept Manticore requests
  --version              display the current version of Buddy
  --help                 display this help message
  --telemetry-period=[N] set period for telemetry when we do snapshots
  --disable-telemetry    disables telemetry for Buddy
  --debug                enable debug mode for testing
  Examples:
  manticore-executor src/main.php --debug
  manticore-executor src/main.php --disable-telemetry

```
Detailed info on  Manticore executor can be found [here](https://github.com/manticoresoftware/executor)
