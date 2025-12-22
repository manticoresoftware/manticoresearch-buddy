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

Each request is handled by Swoole HTTP server, parsed in the main loop, and forwarded to the `QueryProcessor`, which is the main entry point for command detection.

Each command that Buddy can process consists of implementing a plugin with two main classes: `Payload` and `Handler`.

The `Payload` class represents the parsing of raw network data from JSON and its preparation for future request processing. It must implement static methods `fromRequest()` for parsing, `hasMatch()` for request detection, and `getInfo()` for plugin description.

The `Handler` class contains the logic for the command that must be executed. It takes a `Payload` instance in its constructor and implements a `run()` method that returns a `Task` instance, which is executed asynchronously using Swoole coroutines.

Exceptions can be thrown when implementing a new command because they are all caught in a loop and processed.

There is a `GenericError` class that implements the `setResponseError` and `getResponseError` methods. If you want to provide a user-friendly error message, you can use the `setResponseError` method or create an exception with `GenericError::create('User-friendly error message goes here')`. It's important to note that the default exception message will not be included in the user response but in the Manticore log file.

### Helper tool to start new plugin development

We offer a tool that simplifies the process of adding a new plugin. However, this tool is not currently available in the repository. You can manually create a new plugin by following the structure described in the next section.

### Steps for creating a new plugin

Let's take a closer look at an example of how to create a plugin for a RESTORE command:

1. Start by creating a directory with your plugin namespace `src/Plugin/Restore` and implementing the `Payload` and `Handler` classes.
2. In the `Payload` class, implement the `fromRequest()` static method that parses the input network request and returns a new Payload instance with all necessary data loaded.
3. Implement the `hasMatch()` static method that determines if this plugin should handle the given request.
4. In the `Handler` class, implement the `run()` method that contains all the logic for the command and returns a `Task` instance.
5. The plugin will be automatically discovered by the plugin system through namespace scanning - no manual registration is required.

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
  --threads=[N]          start N threads on launch, default is CPU core count
  --log-level=[N]        set log level, default is info, one of debug, debugv, debugvv or info
  Examples:
  manticore-executor src/main.php --log-level=debug
  manticore-executor src/main.php --disable-telemetry
```

You can find more detailed information on the Manticore executor [here](https://github.com/manticoresoftware/executor).


### Development

If you want to contribute to the project and develop extra features, we have prepared a particular docker image.

Just go to your "buddy" folder on a host machine and run the following instructions.

```bash
docker run --privileged --entrypoint bash -v $(pwd):/workdir --name manticore-buddy -it ghcr.io/manticoresoftware/manticoresearch:test-kit-latest
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

… your NS and other copyright here …

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
  static $processors;
  // To ensure the object reference remains unchanged,
  // create it as static and keep track of it.
  if (!$processors) {
    $processors = [new Processor()];
  }
  return $processors;
}
````

### Communication Protocol v3

This is the protocol description used for communication between Manticore Search and Buddy.

You can find communication protocol v1 [here](https://github.com/manticoresoftware/manticoresearch-buddy/tree/8973ad3491e08837f5f518f6165425fb8d94ecf1?tab=readme-ov-file#communication-protocol).
You can find communication protocol v2 [here](https://github.com/manticoresoftware/manticoresearch-buddy/tree/d0b2b2b48935104100f94e0a74e6e867e5ddbc12?tab=readme-ov-file#communication-protocol-v2).

#### Request from Manticore Search to Buddy

The request from Manticore Search to Buddy is made in JSON format no matter how the original query is made (JSON/SQL/binary). The fields are:

| Key | Description |
|-|-|
| `type` | Either `unknown json request` when the original request is made via JSON over HTTP or `unknown sql request` for SQL over HTTP/mysql. |
| `error` | An object containg information about error(error message, etc.) to be returned to the user, if any. |
| `message` | An object containing details such as `path_query` (specific to JSON over HTTP requests), `http_method`  (`HEAD`, `GET`, etc) and `body` which holds the main content of the request. For JSON over HTTP, `path_query` can include specific endpoints like `_doc`, `_create`, etc., while for SQL over HTTP/mysql, it remains empty (`""`). `http_method` is set to `""` for SQL over HTTP/mysql |
| `version` | The maximum protocol version supported by the sender. |

Example of the request:

```json
{
  "type":"unknown json request",
  "error": {
    "message":"unknown option 'fuzzy'",
    "body":{"error":"unknown option 'fuzzy'"}
  },
  "message":{
    "path_query":"/search",
    "body":"{\"index\":\"name\",\"query\":{\"bool\":{\"must\":[{\"match\":{\"*\":\"RICH\"}}]}},\"options\":{\"fuzzy\":true}}",
    "http_method":"POST"
  },
  "version":3
}
```

#### Response from Buddy to Manticore Search

The response JSON structure:

| Key | Description |
|-|-|
| `type` | Set to `json response` if the request type was `unknown json request` and `sql response` for `unknown sql request`. |
| `message` | A JSON object potentially containing an `error` message for displaying and/or logging. This is what Manticore Search will forward to the end-user. |
| `log` | Optional object describing a log entity to be handled by Manticore Search (for example, flushed into `auth.log`). |
| `error_code` | An integer representing the HTTP error code which will be a part of the HTTP response to the user making a JSON over HTTP request. For SQL over HTTP/mysql communications, this field is ignored. |
| `version` | Indicates the current protocol version being used. Current version is 3. |
| `content_type` | Optional string that defines the Content-Type header value for the reply to the client. |


Example of HTTP Response:

```json
{
  "type": "json response",
  "message": {
    "a": 123,
    "b": "abc"
  },
  "error_code": 0,
  "version": 3,
  "content_type": "text/html"
}
```

Example of HTTP Response:

```json
{
  "type": "json response",
  "message": {
    "a": 123,
    "b": "abc"
  },
  "error_code": 0,
  "version": 3
}
```

Example of MySQL Response:

```json
{
  "type": "sql response",
  "message": [
    {
      "columns": [
        {
          "Field": {
            "type": "string"
          }
        },
        {
          "Type": {
            "type": "string"
          }
        },
        {
          "Properties": {
            "type": "string"
          }
        }
      ],
      "data": [
        {
          "Field": "id",
          "Type": "bigint",
          "Properties": ""
        },
        {
          "Field": "title",
          "Type": "text",
          "Properties": "indexed"
        },
        {
          "Field": "gid",
          "Type": "uint",
          "Properties": ""
        },
        {
          "Field": "title",
          "Type": "string",
          "Properties": ""
        },
        {
          "Field": "j",
          "Type": "json",
          "Properties": ""
        },
        {
          "Field": "new1",
          "Type": "uint",
          "Properties": ""
        }
      ],
      "total": 6,
      "error": "",
      "warning": ""
    }
  ],
  "error_code": 0,
  "version": 3
}
```

Note: The structure for error responses in the `message` field follows the guidelines specified in the [Manticore Search documentation](https://github.com/manticoresoftware/manticoresearch/blob/3cefedf3e71a433b9259571e873586ce13444fcd/manual/Connecting_to_the_server/HTTP.md?plain=1#L227-L247) for JSON and similarly structured documentation for SQL interactions.
