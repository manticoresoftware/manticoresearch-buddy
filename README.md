# Manticore Buddy

Manticore Buddy is a sidecar for Manticore Search, written in PHP, which helps with various tasks. The typical workflow is that before returning an error to the user, Manticore Search asks Buddy if it can handle it for the daemon. Buddy is written in PHP, which makes it easy to implement high-level functionalities that do not require extra-high performance.

Read articles about Buddy to get a deep dive:

- [Introducing Buddy: the PHP sidecar for Manticore Search](https://manticoresearch.com/blog/manticoresearch-buddy-intro/)
- [Manticore Buddy: challenges and solutions](https://manticoresearch.com/blog/manticoresearch-buddy-challenges-and-solutions/)
- [Manticore Buddy: pluggable design](https://manticoresearch.com/blog/manticoresearch-buddy-pluggable-design/)

## SQL processing

### Introduction

To control different SQL commands, we use the following architecture. Each command consists of a prefix and the rest of the parameters. For example, we have "backup to /path/to/folder". In this case, `backup` is the command, and `to /path/to/folder` is the rest.

### Execution flow

Each request is handled by ReactPHP, parsed in the main loop, and forwarded to the `QueryProcessor`, which is the main entry point for command detection.

Each command that Buddy can process consists of implementing two interfaces: `CommandRequestInterface` and `CommandExecutorInterface`.

The `CommandRequestInterface` represents the parsing of raw network data from JSON and its preparation for future request processing by the command. Its primary purpose is to parse the data into a `CommandRequest` object and throw exceptions in case of any errors.

There is a base class – `ComandRequestBase`, that implements the required interface and adds some base logic to all requests we support. You should extend this class when you create a new command request.

The `CommandExecutorInterface` contains the logic for the command that must be executed. It uses the `Task` class, which is run in parallel in a separate thread to make the process non-blocking.

Exceptions can be thrown when implementing a new command because they are all caught in a loop and processed.

There is a `GenericError` class that implements the `setResponseError` and `getResponseError` methods. If you want to provide a user-friendly error message, you can use the `setResponseError` method or create an exception with `GenericError::create('User-friendly error message goes here')`. It's important to note that the default exception message will not be included in the user response but in the Manticore log file.

### Helper tool to start new command development

We offer a tool that simplifies the process of adding a new command. Using the tool, you can create a new command with a single command-line instruction like this:

```bash
bin/create-command NameOfTheCommand
```

Once you execute this command, all the necessary structures for the `NameOfTheCommand` command will be created automatically. All that's left for you to do is to review the files and add your code where necessary. For further information on how this tool works, please refer to the next section.

### Steps for creating a new command

Let's take a closer look at an example of how to create an abstract RESTORE command:

1. Start by creating a directory with our command namespace `src/Restore` and implementing the Request and Executor classes.
2. Then, write code that implements the `fromRequest` method in the `Request` class. This method should parse the input network request and return a new Request instance with all the necessary data loaded and ready to be used in the `Executor`.
3. In the `Executor`, write code that implements the run method and contains all the logic for the command. This method should return an instance of the `Task` class.
4. With the `Task` instance, you can check the status, get the result, and have full control over the asynchronous execution of the command.
5.Finally, add a case for our new command to the `extractCommandFromRequest` method of the `QueryProcessor` class. This ensures that the `QueryProcessor` can recognize and handle requests for the `RESTORE` command.

### Debug

To debug the command flow, you can use the `bin/query` script. To run it, pass the query as an argument. For example:

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


This will execute the `BACKUP` command and display the results. The output will present the Manticore configuration, versions, and the status and outcome of the command.

### Running from CLI

To run a Buddy instance from the command line interface (CLI), use the following command:

```bash
  $ manticore-executor src/main.php [ARGUMENTS]
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  Usage: manticore-executor src/main.php [ARGUMENTS]

  Arguments are:
  --bind                 Which IP to bind, default is 127.0.0.1
  --listen               HTTP endpoint to accept Manticore requests
  --version              display the current version of Buddy
  --help                 display this help message
  --telemetry-period=[N] set period for telemetry when we do snapshots
  --disable-telemetry    disables telemetry for Buddy
  --threads=[N]          start N threads on launch, default is 4
  --debug                enable debug mode for testing
  --debugv               enable verbose debug mode with periodic messages
  Examples:
  manticore-executor src/main.php --debug
  manticore-executor src/main.php --disable-telemetry
```

You can find more detailed information on the Manticore executor [here](https://github.com/manticoresoftware/executor).


### Development

If you want to contribute to the project and develop extra features, we have prepared a particular docker image.

Just go to your "buddy" folder on a host machine and run the following instructions.

```bash
docker run --privileged --entrypoint bash -v $(pwd):/workdir --name manticore-buddy  -it ghcr.io/manticoresoftware/manticoresearch:test-kit-latest
ghcr.io/manticoresoftware/manticoresearch:test-kit-latest
```

After that, you can go into the container and work as normal. It has pre-installed Manticoresearchd with the columnar library, production, and development version of executor.

The image is built from Alpine Linux. Please note that you should also run `composer install` before running Buddy from the source code with `manticore-executor`.

If you want to test Buddy somewhere else (not just Alpine), the easiest way to build it as a PHAR archive.

Ensure you are in the directory where the Buddy repository is cloned. And follow the instructions:

```bash
git clone https://github.com/manticoresoftware/phar_builder.git
docker run -rm --entrypoint bash -v $(pwd):/workdir --workdir /workdir --entrypoint bash -it ghcr.io/manticoresoftware/manticoresearch:test-kit-latest
ghcr.io/manticoresoftware/manticoresearch:test-kit-latest -c './phar_builder/bin/build --name="Manticore Buddy" --package="manticore-buddy"
```

Check the build directory and get the built version of Buddy from there and replace it in your another OS in the "modules" directory.

#### Run custom process inside the Plugin

To run the process that can maintain some logic and communicate you need to create the `Processor` class and add `getProcessors` method to the `Payload`

Here is the example that explain how to do So

Create `Processor` plugin and implement required logic

```php
<?php declare(strict_types=1);

… your NS and other copywrite here …

use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Process\Process;

final class Processor extends BaseProcessor {
  public function start(): void {
    var_dump('starting');
    parent::start();

    $this->execute('test', ['simple message']);
  }

  public function stop(): void {
    var_dump('stopping');
    parent::stop();
  }

  public static function test(string $text): void {
    var_dump($text);
  }
}
```

Add to the `Payload` info that your plugin has processors

```php
public static function getProcessors(): array {
  return [
    new Processor(),
  ];
}
````

### Communication protocol v1
>>>>>>> f8fe9d1 (Add info about new protocol to the Readme)

Manticore Buddy and Manticore Search communicates with each other in strict accordance with the following protocol:

#### JSON over HTTP, SQL over HTTP

#### C++ sends to PHP on the HTTP request

json of:
- `type`: `unknown json request`
- `error`: error text which would be returned to the user
- `message`:
  - `path_query`. E.g. in Feb 2024, it's one of: `_doc`, `_create`, `_udpate`, `_mapping`, `bulk`, `_bulk`, `cli`, `cli_json`, `search`, `sql?mode=raw`, `sql`, `insert`, `_license`, (empty value) other will return an error as Buddy does not support it.
  - `body`
- `version`: max buddy protocol version it can handle (the current one is 1)

#### PHP returns to C++ on the HTTP request

json of:
- `type`: `json response`
- `message`: the json to return to the user
- `error`: error to log to searchd log in case the query couldn't be completed in PHP
- `version`: it's current protocol version (the current one is 1)

Example:

```json
{
  "type": "json response",
  "message": {
    "a": 123,
    "b": "abc"
  },
  "error": "",
  "version": 1
}
```

#### SQL over MySQL

#### C++ sends to PHP on the MySQL request

json of:
- `type`: `unknown sql request`
- `user`: mysql user name
- `error`: error text which would be returned to the user
- `message`: SQL query failed to execute
  - `path_query`: `""` (empty)
  - `body`: the original SQL query itself
- `version`: max buddy protocol version it can handle (the current one is 1)

#### PHP returns to C++ on the MySQL request

- `type`: `sql response`
- `message`: the same `/cli` returns as json (i.e. an array)
- `error`: error to log to searchd log in case the query couldn't be completed in PHP
- `version`: it's current protocol version (the current one is 1)

#### Headers

When daemon sends a request to Buddy it should include header `Request-ID: smth`

### Communication Protocol v2

#### JSON over HTTP, SQL over HTTP v2

#### C++ Sends to PHP via HTTP Request v2

JSON format includes:
- `type`: "unknown json request"
- `error`: The error message to be returned to the user
- `message`:
  - `path_query`. For example, in February 2024, it can be one of the following: `_doc`, `_create`, `_update`, `_mapping`, `bulk`, `_bulk`, `cli`, `cli_json`, `search`, `sql?mode=raw`, `sql`, `insert`, `_license`, or an (empty value). Any other value will return an error since Buddy does not support it.
  - `body`
- `version`: The maximum Buddy protocol version it can handle (currently version 1)

#### PHP Returns to C++ via HTTP Request v2

JSON format includes:
- `type`: "json response"
- `message`: This JSON object is returned to the user and may contain an `error` message for display. Additionally, Buddy has the capability to log this error using standard output. This response is precisely what Manticore will forward to the user, adhering to the guidelines specified in the [Manticore documentation](https://github.com/manticoresoftware/manticoresearch/blob/3cefedf3e71a433b9259571e873586ce13444fcd/manual/Connecting_to_the_server/HTTP.md?plain=1#L227-L247). For error response it's a struct: `{"error":"..."}`
- `error_code`: An integer representing the HTTP error code that the daemon should use to respond to the client. If it's `0`, Manticore should return the original error code to the client.
- `version`: An integer indicating the current protocol version.

Example:

```json
{
  "type": "json response",
  "message": {
    "a": 123,
    "b": "abc"
  },
  "error_code": 0,
  "version": 1
}
```

#### SQL over MySQL v2

#### C++ Sends to PHP via MySQL Request v2

JSON format includes:
- `type`: "unknown sql request"
- `user`: MySQL username
- `error`: The error message to be returned to the user
- `message`: Details on the SQL query that failed to execute
  - `path_query`: `""` (empty)
  - `body`: The original SQL query
- `version`: The maximum Buddy protocol version it can handle (currently version 2)

#### PHP Returns to C++ on the MySQL Request

- `type`: "sql response"
- `message`: This JSON object is returned to the user and may contain an `error` message for display. Additionally, Buddy has the capability to log this error using standard output. This response is precisely what Manticore will forward to the user, adhering to the guidelines specified in the [Manticore documentation](https://github.com/manticoresoftware/manticoresearch/blob/3cefedf3e71a433b9259571e873586ce13444fcd/manual/Connecting_to_the_server/HTTP.md?plain=1#L126-L185). For error response it's a struct: `{"error":"..."}`
- `error_code`: An integer representing the HTTP error code that the daemon should use to respond to the client. If it's `0`, Manticore should return the original error code to the client.
- `version`: The current protocol version
