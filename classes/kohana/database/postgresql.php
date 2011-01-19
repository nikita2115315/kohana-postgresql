<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PostgreSQL database connection.
 *
 * @package     PostgreSQL
 * @author      Chris Bandy
 * @copyright   (c) 2010 Chris Bandy
 * @license     http://www.opensource.org/licenses/isc-license.txt
 */
class Kohana_Database_PostgreSQL extends Database
{
	protected $_version;

	protected function __construct($name, $config)
	{
		parent::__construct($name, $config);

		if (empty($this->_config['connection']['info']))
		{
			// Build connection string
			$this->_config['connection']['info'] = '';

			extract($this->_config['connection']);

			if ( ! empty($hostname))
			{
				$info .= "host='$hostname'";
			}

			if ( ! empty($port))
			{
				$info .= " port='$port'";
			}

			if ( ! empty($username))
			{
				$info .= " user='$username'";
			}

			if ( ! empty($password))
			{
				$info .= " password='$password'";
			}

			if ( ! empty($database))
			{
				$info .= " dbname='$database'";
			}

			if (isset($ssl))
			{
				if ($ssl === TRUE)
				{
					$info .= " sslmode='require'";
				}
				elseif ($ssl === FALSE)
				{
					$info .= " sslmode='disable'";
				}
				else
				{
					$info .= " sslmode='$ssl'";
				}
			}

			$this->_config['connection']['info'] = $info;
		}
	}

	public function connect()
	{
		if ($this->_connection)
			return;

		try
		{
			$this->_connection = empty($this->_config['connection']['persistent'])
				? pg_connect($this->_config['connection']['info'], PGSQL_CONNECT_FORCE_NEW)
				: pg_pconnect($this->_config['connection']['info'], PGSQL_CONNECT_FORCE_NEW);
		}
		catch (ErrorException $e)
		{
			throw new Database_Exception(':error', array(':error' => $e->getMessage()));
		}

		if ( ! is_resource($this->_connection))
			throw new Database_Exception('Unable to connect to PostgreSQL ":name"', array(':name' => $this->_instance));

		$this->_version = pg_parameter_status($this->_connection, 'server_version');

		if ( ! empty($this->_config['charset']))
		{
			$this->set_charset($this->_config['charset']);
		}

		if (empty($this->_config['schema']))
		{
			// Assume the default schema without changing the search path
			$this->_config['schema'] = 'public';
		}
		else
		{
			if ( ! pg_send_query($this->_connection, 'SET search_path = '.$this->_config['schema'].', pg_catalog'))
				throw new Database_Exception(pg_last_error($this->_connection));

			if ( ! $result = pg_get_result($this->_connection))
				throw new Database_Exception(pg_last_error($this->_connection));

			if (pg_result_status($result) !== PGSQL_COMMAND_OK)
				throw new Database_Exception(pg_result_error($result));
		}
	}

	public function disconnect()
	{
		if ( ! $status = ! is_resource($this->_connection))
		{
			if ($status = pg_close($this->_connection))
			{
				$this->_connection = NULL;
			}
		}

		return $status;
	}

	public function set_charset($charset)
	{
		$this->_connection or $this->connect();

		if (pg_set_client_encoding($this->_connection, $charset) !== 0)
			throw new Database_Exception(pg_last_error($this->_connection));
	}

	public function query($type, $sql, $as_object = FALSE, array $params = NULL)
	{
		$this->_connection or $this->connect();

		if ( ! empty($this->_config['profiling']))
		{
			// Benchmark this query for the current instance
			$benchmark = Profiler::start("Database ({$this->_instance})", $sql);
		}

		try
		{
			if ($type === Database::INSERT AND $this->_config['primary_key'])
			{
				$sql .= ' RETURNING '.$this->quote_identifier($this->_config['primary_key']);
			}

			if ( ! pg_send_query($this->_connection, $sql))
				throw new Database_Exception(':error [ :query ]',
					array(':error' => pg_last_error($this->_connection), ':query' => $sql));

			if ( ! $result = pg_get_result($this->_connection))
				throw new Database_Exception(':error [ :query ]',
					array(':error' => pg_last_error($this->_connection), ':query' => $sql));

			// Check the result for errors
			switch (pg_result_status($result))
			{
				case PGSQL_COMMAND_OK:
					$rows = pg_affected_rows($result);
				break;
				case PGSQL_TUPLES_OK:
					$rows = pg_num_rows($result);
				break;
				case PGSQL_BAD_RESPONSE:
				case PGSQL_NONFATAL_ERROR:
				case PGSQL_FATAL_ERROR:
					throw new Database_Exception(':error [ :query ]',
						array(':error' => pg_result_error($result), ':query' => $sql));
				case PGSQL_COPY_OUT:
				case PGSQL_COPY_IN:
					pg_end_copy($this->_connection);

					throw new Database_Exception('PostgreSQL COPY operations not supported [ :query ]',
						array(':query' => $sql));
				default:
					$rows = 0;
			}

			if (isset($benchmark))
			{
				Profiler::stop($benchmark);
			}

			$this->last_query = $sql;

			if ($type === Database::SELECT)
				return new Database_PostgreSQL_Result($result, $sql, $as_object, $params, $rows);

			if ($type === Database::INSERT)
			{
				if ($this->_config['primary_key'])
				{
					// Fetch the first column of the last row
					$insert_id = pg_fetch_result($result, $rows - 1, 0);
				}
				elseif ($insert_id = pg_send_query($this->_connection, 'SELECT LASTVAL()'))
				{
					if ($result = pg_get_result($this->_connection) AND pg_result_status($result) === PGSQL_TUPLES_OK)
					{
						$insert_id = pg_fetch_result($result, 0);
					}
				}

				return array($insert_id, $rows);
			}

			return $rows;
		}
		catch (Exception $e)
		{
			if (isset($benchmark))
			{
				Profiler::delete($benchmark);
			}

			throw $e;
		}
	}

	/**
	 * @link http://www.postgresql.org/docs/current/static/datatype.html#DATATYPE-TABLE
	 */
	public function datatype($type)
	{
		static $types = array
		(
			// PostgreSQL >= 7.4
			'box'       => array('type' => 'string'),
			'bytea'     => array('type' => 'string', 'binary' => TRUE),
			'cidr'      => array('type' => 'string'),
			'circle'    => array('type' => 'string'),
			'inet'      => array('type' => 'string'),
			'int2'      => array('type' => 'int', 'min' => '-32768', 'max' => '32767'),
			'int4'      => array('type' => 'int', 'min' => '-2147483648', 'max' => '2147483647'),
			'int8'      => array('type' => 'int', 'min' => '-9223372036854775808', 'max' => '9223372036854775807'),
			'line'      => array('type' => 'string'),
			'lseg'      => array('type' => 'string'),
			'macaddr'   => array('type' => 'string'),
			'money'     => array('type' => 'float', 'exact' => TRUE, 'min' => '-92233720368547758.08', 'max' => '92233720368547758.07'),
			'path'      => array('type' => 'string'),
			'point'     => array('type' => 'string'),
			'polygon'   => array('type' => 'string'),
			'text'      => array('type' => 'string'),

			// PostgreSQL >= 8.3
			'tsquery'   => array('type' => 'string'),
			'tsvector'  => array('type' => 'string'),
			'uuid'      => array('type' => 'string'),
			'xml'       => array('type' => 'string'),
		);

		if (isset($types[$type]))
			return $types[$type];

		return parent::datatype($type);
	}

	public function list_tables($like = NULL)
	{
		$this->_connection or $this->connect();

		$sql = 'SELECT table_name FROM information_schema.tables WHERE table_schema = '.$this->quote($this->schema());

		if (is_string($like))
		{
			$sql .= ' AND table_name LIKE '.$this->quote($like);
		}

		return $this->query(Database::SELECT, $sql, FALSE)->as_array(NULL, 'table_name');
	}

	public function list_columns($table, $like = NULL, $add_prefix = TRUE)
	{
		$this->_connection or $this->connect();

		$sql = 'SELECT column_name, column_default, is_nullable, data_type, character_maximum_length, numeric_precision, numeric_scale, datetime_precision'
			.' FROM information_schema.columns'
			.' WHERE table_schema = '.$this->quote($this->schema())
			.' AND table_name = '.$this->quote($add_prefix ? ($this->table_prefix().$table) : $table);

		if (is_string($like))
		{
			$sql .= ' AND column_name LIKE '.$this->quote($like);
		}

		$sql .= ' ORDER BY ordinal_position';

		$result = array();

		foreach ($this->query(Database::SELECT, $sql, FALSE) as $column)
		{
			$column = array_merge($this->datatype($column['data_type']), $column);

			$column['is_nullable'] = ($column['is_nullable'] === 'YES');

			$result[$column['column_name']] = $column;
		}

		return $result;
	}

	public function schema()
	{
		return $this->_config['schema'];
	}

	public function escape($value)
	{
		$this->_connection or $this->connect();

		$value = pg_escape_string($this->_connection, $value);

		return "'$value'";
	}
}
