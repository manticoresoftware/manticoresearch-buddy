# Manticore Buddy

Manticore Buddy is a sidecar for Manticore Search, written in PHP, which helps with various tasks. The typical workflow is that before returning an error to the user, Manticore Search asks Buddy if it can handle it for the daemon. Buddy is written in PHP, which makes it easy to implement high-level functionalities that do not require extra-high performance.

## SQL processing

### Introduction

To control different SQL commands, we use the following architecture. Each command consists of a prefix and the rest of the parameters. For example, we have "backup to /path/to/folder". In this case, `backup` is the command, and `to /path/to/folder` is the rest.

### The flow

Each request is handled by ReactPHP, parsed in the main loop, and forwarded to the `QueryProcessor`, which is the main entry point for command detection.

Each command that Buddy can process consists of the implementation of two interfaces: `CommandRequestInterface` and `CommandExecutorInterface`.

The `CommandRequestInterface` represents the parsing of raw network data from JSON and its preparation for future request processing by the command. Its primary purpose is to parse the data into a `CommandRequest` object and throw exceptions in case of any errors.

The `CommandExecutorInterface` contains the logic for the command that must be executed. It uses the `Task` class, which is run in parallel in a separate thread to make the process nonblocking.

Exceptions can be thrown when implementing a new command because they are all caught in a loop and processed.

There is a `GenericError` class that implements the `setResponseError` and `getResponseError` methods. If you want to provide a user-friendly error message, you can use the `setResponseError` method or create an exception with `GenericError::create('User-friendly error message goes here')`. It's important to note that the default exception message will not be included in the user response, but rather in the Manticore log file.

### Steps example of command creation

Great! Let's take a look at an example of creating the abstract RESTORE command:

1. First, create a directory with our command namespace src/Restore and implement the `Request` and `Executor` classes.

2. Next, write code that implements the `fromNetworkRequest` method in the `Request` class. This method should parse the input network request and return a new `Request` instance with all data loaded in it, ready to be used in the `Executor`.

3. In the `Executor` class, write code that implements the `run` method and contains all the logic for the command. This method should return an instance of the `Task` class.

4. The `Task` instance can be used to check the status, get the result, and have complete control over the asynchronous execution of the command.

5. Finally, add a case for our new command to the `extractCommandFromRequest` method of the `QueryProcessor` class. This will allow the `QueryProcessor` to recognize and handle requests for the RESTORE command.


### Debug

To debug the flow of the command, you can use the `bin/query` script. To run it, pass the query as an argument. For example:

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

This will execute the `BACKUP` command and print some results. The output will show the Manticore configuration, versions, and the status and result of the command.

### Running from CLI

To run a Buddy instance from the command line interface (CLI), use the following command:

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
You can find more detailed information on the Manticore executor [here](https://github.com/manticoresoftware/executor).
