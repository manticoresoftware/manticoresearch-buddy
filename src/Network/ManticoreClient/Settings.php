<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Network\ManticoreClient;

final class Settings {
	public ?string $configurationFile = null;
	public ?int $workerPid = null;
	public bool $searchdAutoSchema = true;
	public ?string $searchdListen = null;
	public ?string $searchdLog = null;
	public ?string $searchdQueryLog = null;
	public ?string $searchdPidFile = null;
	public ?string $searchdDataDir = null;
	public ?string $searchdQueryLogFormat = null;
	public ?string $searchdBuddyPath = null;
	public ?string $searchdBinlogPath = null;
	public ?string $commonPluginDir = null;
	public ?string $commonLemmatizerBase = null;

	const CAST = [
		'searchdAutoSchema' => 'boolean',
	];

	/**
	 * @param array{
   * 'configuration_file'?:string,
   * 'worker_pid'?:int,
   * 'searchd.auto_schema'?:string,
   * 'searchd.listen'?:string,
   * 'searchd.log'?:string,
   * 'searchd.query_log'?:string,
   * 'searchd.pid_file'?:string,
   * 'searchd.data_dir'?:string,
   * 'searchd.query_log_format'?:string,
   * 'searchd.buddy_path'?:string,
   * 'searchd.binlog_path'?:string,
   * 'common.plugin_dir'?:string,
   * 'common.lemmatizer_base'?:string,
   * } $settings
	 * @return static
	 */
	public static function fromArray(array $settings): static {
		$self = new static;
		foreach ($settings as $key => $value) {
			$property = \underscore_to_camelcase(str_replace('.', '_', $key));
			if (!property_exists(static::class, $property)) {
				continue;
			}
			if (isset(static::CAST[$property])) {
				settype($value, static::CAST[$property]);
			}
			$self->$property = $value;
		}

		$settingsJson = json_encode($self);
		debug("settings: $settingsJson");
		return $self;
	}

	/**
	 * Detect if manticore runs in RT mode
	 * @return bool
	 */
	public function isRtMode(): bool {
		return isset($this->searchdDataDir);
	}
}
