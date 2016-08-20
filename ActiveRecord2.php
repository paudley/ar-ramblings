<?php
/** Base class for ActiveRecords. Extend this and fill in the
 * abstract methods for profit.
 *
 * @license GPLv2
 * @copyright Patrick Audley <paudley@blackcat.ca> 2010
 */
abstract class ActiveRecord {

	/** Our internal database handle.
	 *  @var PDO
	 */
	static private $_dbh = NULL;

	/** Set the PDO database handle for all ActiveRecords.
	 *  @param PDO A PDO database handle, created using "new PDO(...)".
	 *  @note Call this once per session.
	 */
	static public function setDb( PDO $dbh ) { self::$_dbh = $dbh; }

	/** Get the current table name.
	 *  @return string SQL table name this class.
	 */
	abstract public function tableName();

	/** Get the primary key name.
	 *  @return string the name of the primary key. */
	public function primaryKey() { return 'id'; }

  /** Primary key for this record, NULL if it's a new one.
	 *  @var integer (usually)
	 */
	private $_id = NULL;

	/** The cached data from the db.
	 *  @var array
	 */
	private $_data = NULL;

	/** Constructor
	 *  @param integer $id The primary key to load, or NULL if it's new.
	 */
	public function __construct( $id = NULL ) {
		// New record, don't attempt to load.
		if( $id == NULL ) return;
		$this->_load( $id );
	}

	/** Are we dirty?  (do we need writing to the database?)
	 *  @var booleon
	 */
	protected $_dirty = FALSE;

	/** General Mutator.
	 *  @param string $name  The column name to update.
	 *  @param mixed  $value The value to set it to.
	 *  @return ActiveRecord Returns self for fluent interface.
	 */
	final public function __set( $name, $value ) {
		if( $this->_id !== NULL and !array_key_exists( $name, $this->_data ) )
			throw new Exception( 'Column "'.$name.'" does not exists in table "'.$this->tableName().'" in set.' );
		$this->_data[$name] = $value;
		$this->_dirty = TRUE;
		return $this;
	}

	// #DEBUG-START
	/** Are we debugging?
	 *  @var boolean
	 *
	 *  @note Turn this one with this code:  ActiveRecord::$debug = TRUE
	 */
	static public $debug = FALSE;
	// #DEBUG-END

	// #PREPARE-QUERY-START
	/** Centralize prepared database queries so we can inject debugging.
	 *  @param string $sql    The SQL to excute.
	 *  @param array  $params The parameters to bind to the above query.
	 *  @return array of rows from DB.
	 */
	private function _query( $sql, array $params = array() ) {
		// Add some debugging since we have all the query bits available for display.
		if( self::$debug === TRUE )
			echo ' sql debug: '.$sql.'; '.preg_replace( '/\n/', '', var_export( $params, TRUE ) )."\n";
		$stmt = self::$_dbh->prepare( $sql );
		if( count( $params ) > 0 )
			foreach( $params as $column => $value )
				$stmt->bindValue( $column, $value );
		$stmt->execute();
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
		if( self::$debug === TRUE )
			echo '   returned: '.count( $rows )." rows\n";
		return $rows;
	}
	// #PREPARE-QUERY-END

	/** General Accessor.
	 *  @param sting $name The column name to get.
	 *  @return mixed The requested data.
	 */
	final public function __get( $name ) {
		if( $name == $this->primaryKey() )
			return $this->_id;
		if( $this->_id !== NULL and !array_key_exists( $name, $this->_data ) )
			throw new Exception( 'Column "'.$name.'" does not exists in table "'.$this->tableName().'" in get.' );
		return array_key_exists( $name, $this->_data ) ? $this->_data[$name] : NULL;
	}

	// #PREPARE-LOAD-START
	/** Load a row from the database.
	 *  @return void
	 */
	private function _load( $id ) {
		$this->_id = $id;
		// Issue the SELECT
		$rows = $this->_query( 'SELECT * FROM '.$this->tableName()
																.' WHERE `'.$this->primaryKey().'` = :id',
													 array( ':id' => $id ) );
		if( count( $rows ) < 1 )
			throw new Exception( 'Bad ID in ActiveRecord Fetch for '.get_called_class().':'.$id );
		$this->_data = $rows[0];
		unset( $this->_data[ $this->primaryKey() ] );
	}
	// #PREPARE-LOAD-END

	// #PREPARE-SAVE-START
	/** Write this record to the underlying database.
	 *  @return boolean TRUE if successful.
	 */
	public function save() {
		// Skip writing clean records.
		if( !$this->_dirty ) return TRUE;

		// This is used to bind the local object in the anonymous functions below.
		$base = $this;
		$params = array();

		// Handle UPDATEs
		if( $this->_id !== NULL ) {
			// This is a complex bit of PHP, I was feeling perl-ish, excuse me...
			$sql = 'UPDATE '.$this->tableName().' SET '
				. implode( ', ', array_map( function( $column ) use ( $base, &$params ) {
							$params[':'.$column] = $base->$column;
							return '`'.$column.'` = :'.$column;
						}, array_keys( $this->_data ) ) )
				. ' WHERE `'.$this->primaryKey().'` = "'.$this->_id.'"';
			$this->_query( $sql, $params );
			return TRUE;
		}

		// Handle INSERTs.
		$sql = 'INSERT INTO '.$this->tableName().' (`'
			. implode( '`, `', array_keys( $this->_data ) ).'`) VALUES (:'
			. implode( ',:', array_keys( $this->_data ) ).')';
		array_walk( $this->_data,
								function( $value, $column ) use ( $base, &$params ) {
									$params[':'.$column] = $value;
								} );
		$this->_query( $sql, $params );
		$this->_id = self::$_dbh->lastInsertId();
		return TRUE;
	}
	// #PREPARE-SAVE-END

	// #PREPARE-REMOVE-START
	/** Remove this record from the database.
	 *  @return void
	 */
	public function remove() {
		// Records that haven't been saved can't be removed.
		if( $this->_id == NULL )
			return;
		// Issue the DELETE SQL
		$sql = 'DELETE FROM '.$this->tableName()
			.' WHERE `'.$this->primaryKey().'` = :id';
		$this->_query( $sql, array( ':id' => $this->_id ) );
		$this->_id = NULL;
	}
	// #PREPARE-REMOVE-END

	// #FACTORY-START
	/** Find the primary keys of matching objects.
	 *  @param string $sql_clause The SQL WHERE clause to use.
	 *  @note This method is not SQL injection safe, please wear extra safety pants when calling.
	 */
	static public function search( $sql_clause ) {
		$ar_class = get_called_class();
		$base = new $ar_class( NULL );
		$sql = 'SELECT *,`'.$base->primaryKey().'` FROM '.$base->tableName().' WHERE '.$sql_clause;
		$rows = $base->_query( $sql );
		$results = array();
		if( count( $rows ) > 0 )
			foreach( $rows as $row )
				$results[] = $row[$base->primaryKey()];
		return $results;
	}
	// #FACTORY-END

}
