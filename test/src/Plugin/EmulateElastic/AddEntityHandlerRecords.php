<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if not, see <https://www.gnu.org/licenses/>.
*/

namespace Manticoresearch\BuddyTest\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Response;

final class AddEntityHandlerRecords {
	/** @var list<string> */
	public array $queries = [];

	public bool $failNextInsert = false;
	public bool $hasDuplicates = false;

	public function __construct(public bool $entityExists) {
	}

	public function record(string $query): Response {
		$this->queries[] = $query;
		if (str_starts_with($query, 'SELECT id FROM system.kibana_entities')) {
			return $this->getEntityResponse();
		}
		if (str_starts_with($query, 'DELETE FROM system.kibana_entities WHERE id IN')) {
			$this->hasDuplicates = false;
		} elseif (str_starts_with($query, 'DELETE FROM system.kibana_entities')) {
			$this->entityExists = false;
		}
		if (str_starts_with($query, 'INSERT INTO system.kibana_entities')) {
			return $this->getInsertResponse();
		}
		if (str_starts_with($query, 'REPLACE INTO system.kibana_entities')) {
			$this->entityExists = true;
		}
		return Response::fromBody('[{"error":"","warning":"","total":0,"data":[]}]');
	}

	private function getEntityResponse(): Response {
		if ($this->hasDuplicates) {
			$data = '[{"id":123},{"id":122},{"id":121}]';
			$total = 3;
		} else {
			$data = $this->entityExists ? '[{"id":123}]' : '[]';
			$total = $this->entityExists ? 1 : 0;
		}
		return Response::fromBody(
			'[{"error":"","warning":"","total":' . $total . ',"data":' . $data . '}]'
		);
	}

	private function getInsertResponse(): Response {
		$this->entityExists = true;
		if (!$this->failNextInsert) {
			return Response::fromBody('[{"error":"","warning":"","total":0,"data":[]}]');
		}
		$this->failNextInsert = false;
		return Response::fromBody('{"error":"duplicate id"}');
	}
}
