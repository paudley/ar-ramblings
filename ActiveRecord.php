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

	/** Load a row from the database.
	 *  @return void
	 */
	private function _load( $id ) {
		$this->_id = $id;
		// Issue the SELECT
		$rows = self::$_dbh->query( 'SELECT * FROM '.$this->tableName()
																.' WHERE `'.$this->primaryKey().'` = \''.$id.'\'' );
		$row = $rows->fetch( PDO::FETCH_ASSOC );
		if( $row === FALSE )
			throw new Exception( 'Bad ID in ActiveRecord Fetch for '.get_called_class().':'.$id );
		$this->_data = $row;
		unset( $this->_data[ $this->primaryKey() ] );
	}

	/** Write this record to the underlying database.
	 *  @return boolean TRUE if successful.
	 */
	public function save() {
		// Skip writing clean records.
		if( !$this->_dirty ) return TRUE;
		// Handle UPDATEs
		$base = $this;
		if( $this->_id !== NULL ) {
			// This is a complex bit of PHP, I was feeling perl-ish, excuse me...
			$sql = 'UPDATE '.$this->tableName().' SET '
				. implode( ', ', array_map( function( $column ) use ( $base ) {
							return '`'.$column.'` = "'.$base->$column.'"';
						}, array_keys( $this->_data ) ) )
				. ' WHERE `'.$this->primaryKey().'` = "'.$this->_id.'"';
			return self::$_dbh->query( $sql ) !== FALSE;
		}
		// Handle INSERTs.
		$sql = 'INSERT INTO '.$this->tableName().' (`'.$this->primaryKey().'`,`'
			. implode( '`, `', array_keys( $this->_data ) ).'`) VALUES (NULL, "'
			. implode( '", "', array_values( $this->_data ) ).'")';
		$rc = self::$_dbh->query( $sql );
		if( $rc === FALSE ) return FALSE;
		$this->_id = self::$_dbh->lastInsertId();
		return TRUE;
	}

	/** Remove this record from the database.
	 *  @return void
	 */
	public function remove() {
		// Records that haven't been saved can't be removed.
		if( $this->_id == NULL )
			return;
		// Issue the DELETE SQL
		$sql = 'DELETE FROM '.$this->tableName()
			.' WHERE `'.$this->primaryKey().'` = "'.$this->_id.'"';
		$rc = ( self::$_dbh->query( $sql ) !== FALSE );
		if( $rc == TRUE )
			$this->_id = NULL;
		return $rc;
	}

}
