<?php

declare(strict_types=1);

namespace flight\apm\writer;

interface WriterSqlInterface
{
	/**
	 * The way that the last insert ID is retrieved can be different
	 *
	 * @return string
	 */
	public function getLastInsertId();
}	