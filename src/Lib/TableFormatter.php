<?php declare(strict_types=1);

/**
 * ArrayToTextTable
 *
 * Display arrays in terminal
 *
 * @author      Mathieu Viossat <mathieu@viossat.fr>
 * @copyright   Copyright (c) 2015 Mathieu Viossat
 * @license     http://opensource.org/licenses/MIT
 * @link        https://github.com/MathieuViossat/arraytotexttable
 */

namespace Manticoresearch\Buddy\Lib;

class TableFormatter {

	const BORDER_VERTICAL = '|';
	const BORDER_HORIZONTAL = '-';
	const BORDER_CORNER = '+';

	/** @var array<int,array<string,string>> $data */
	protected $data;

	/** @var array<int,string> $keys */
	protected $keys;

	/** @var array<int> $widths */
	protected $widths;

	/** @var string $table */
	protected $table;

	/**
	 * @param ?array<array<string,mixed>|object> $origData
	 * @return void
	 */
	public function __construct(?array $origData = []) {
		$this->setData($origData);
		$this->table = '';
	}

	public function __toString() {
		return $this->table;
	}

	/**
	 * @param float $startTime
	 * @param ?array<mixed> $origData
	 * @param ?int $total
	 * @return string
	 */
	public function getTable(
		float $startTime,
		?array $origData = null,
		?int $total = null,
		?string $error = null
	): string {
		if ($error !== null) {
			return "ERROR: $error" . PHP_EOL . PHP_EOL;
		}
		$table = '';
		if ($origData !== null) {
			$this->setData($origData);
		}
		$data = $this->prepare();
		if (!empty($data)) {
			$borderLine = $this->borderLine();
			$table .= $borderLine . PHP_EOL;
			$keysRow = array_combine($this->keys, $this->keys);
			$table .= implode(PHP_EOL, $this->row($keysRow)) . PHP_EOL;
			$table .= $borderLine . PHP_EOL;
			foreach ($data as $row) {
				$table .= implode(PHP_EOL, $this->row($row)) . PHP_EOL;
			}
			$table .= $borderLine . PHP_EOL;
		}
		if ($total === null) {
			return $table;
		}
		// Adding the summary info row
		$totalRow = ($origData === null) ? 'Query OK, ' : '';
		$totalRow .= match (true) {
			($total === 0) => ($origData === null) ? '0 rows affected ' : 'Empty set ',
			($total === 1) => '1 row ' . ($origData === null ? 'affected ' : 'in set '),
			default => "$total rows " . ($origData === null ? 'affected ' : 'in set '),
		};
		$duration = number_format(((hrtime(true) - $startTime) / 1e+9), 3);
		$totalRow .= "($duration sec)";

		return $table . $totalRow . PHP_EOL . PHP_EOL;
	}

	/**
	 * @param ?array<mixed> $data
	 * @return self
	 */
	public function setData(?array $data): self {
		if (!is_array($data)) {
			$data = [];
		}

		$arrayData = [];
		foreach ($data as $row) {
			if (is_object($row)) {
				$row = get_object_vars($row);
			} elseif (!is_array($row)) {
				$row = [$row];
			}
			foreach ($row as $k => $v) {
				if (is_array($v)) {
					$v = json_encode($v);
				}
				$row[$k] = (string)$v;
			}
			$arrayData[] = $row;
		}

		/** @var array<int,array<string,string>> $arrayData */
		$this->data = $arrayData;
		return $this;
	}

	/**
	 * @return string
	 */
	protected function borderLine() {
		$line = self::BORDER_CORNER;
		foreach ($this->keys as $key) {
			$line .= str_repeat(self::BORDER_HORIZONTAL, $this->widths[$key] + 2) . self::BORDER_CORNER;
		}

		return $line;
	}

	/**
	 * @param array<string,string> $row
	 * @return array<int,string>
	 */
	protected function row(array $row): array {
		$data = [];
		$height = 1;
		foreach ($this->keys as $key) {
			$data[$key] = isset($row[$key]) ? static::valueToLines($row[$key]) : [''];
			$height = max($height, sizeof($data[$key]));
		}

		$rowLines = [];
		for ($i = 0; $i < $height; $i++) {
			$rowLine = [];
			foreach ($data as $key => $value) {
				$rowLine[$key] = isset($value[$i]) ? $value[$i] : '';
			}
			$rowLines[] = $this->rowLine($rowLine);
		}

		return $rowLines;
	}

	/**
	 * @param array<string,string> $row
	 * @return string
	 */
	protected function rowLine(array $row): string {
		$line = self::BORDER_VERTICAL;
		foreach ($row as $key => $value) {
			$line .= ' ' . static::mbStrPad($value, $this->widths[$key]) . ' ' . self::BORDER_VERTICAL;
		}
		if (empty($row)) {
			$line .= self::BORDER_VERTICAL;
		}

		return $line;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	protected function prepare(): array {
		$this->keys = [];
		$this->widths = [];
		$data = $this->data;
		if (empty($data)) {
			return $data;
		}
		$this->keys = array_keys($data[0]);
		foreach ($this->keys as $key) {
			$this->setWidth($key, $key);
		}
		foreach ($data as $row) {
			foreach ($row as $columnKey => $columnValue) {
				$this->setWidth($columnKey, (string)$columnValue);
			}
		}
		return $data;
	}

	/**
	 * @param string $string
	 * @return int
	 */
	protected static function countCJK($string): int {
		$res = preg_match_all('/[\p{Han}\p{Katakana}\p{Hiragana}\p{Hangul}]/u', $string);
		return ($res === false) ? 0 : $res;
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @return void
	 */
	protected function setWidth($key, $value): void {
		if (!isset($this->widths[$key])) {
			$this->widths[$key] = 0;
		}

		foreach (static::valueToLines($value) as $line) {
			//$width = mb_strlen($line) + self::countCJK($line);
			$width = strlen($line) + self::countCJK($line);
			if ($width <= $this->widths[$key]) {
				continue;
			}
			$this->widths[$key] = $width;
		}
	}

	/**
	 * @param string $value
	 * @return array<string>
	 */
	protected static function valueToLines(string $value): array {
		return explode("\n", $value);
	}

	/**
	 * @param string $input
	 * @param int $padLength
	 * @return string
	 */
	protected static function mbStrPad($input, $padLength): string {
		//$encoding = mb_internal_encoding();
		//$padLength -= mb_strlen($input, $encoding) + self::countCJK($input);
		$padLength -= strlen($input) + self::countCJK($input);
		return $input . str_repeat(' ', max(0, $padLength));
	}

}
