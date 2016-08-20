<?php
error_reporting( E_ALL | E_STRICT );

// Clean up the old db.
!file_exists( './ar.db' ) ?: unlink( './ar.db' );

// Setup our database.
$dbh = new PDO('sqlite:./ar.db');
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbh->exec( 'CREATE TABLE creatures ( id INTEGER PRIMARY KEY AUTOINCREMENT, species VARCHAR, threat_level INTEGER, food VARCHAR ); ');
$dbh->exec( "INSERT INTO creatures (id,species,threat_level,food) VALUES (1,'Moose',4,'woodland shrubs')" );
$dbh->exec( "INSERT INTO creatures (id,species,threat_level,food) VALUES (2,'Zombie',10,'braaainnns')" );
$dbh->exec( "INSERT INTO creatures (id,species,threat_level,food) VALUES (3,'Duck',1,'floaty bits')" );

// Load our Active Record Class.
require_once __DIR__.'/ActiveRecord.php';
ActiveRecord::setDb( $dbh );

// Create our Active Record for this Creatures table.
class Creature extends ActiveRecord {
	public function tableName() { return 'creatures'; }
}

echo "<pre>\n";
// Load and update a record.
$moose = new Creature( 1 );
$moose->food = 'pirates';
$moose->save();
$moosecheck = new Creature( 1 );
echo 'Moose Modification Test: '.( $moosecheck->food == 'pirates' ? 'PASS' : 'FAILED' )."\n";

// Create a new record.
$cat = new Creature( NULL );
$cat->threat_level = 12;
$cat->species = 'Cat';
$cat->food = 'mice';
$cat->save();
echo 'Cat Insertion Test: '.( $cat->id == 4 ? 'PASS' : 'FAILED' )."\n";

// Delete a record.
$zombie = new Creature( 2 );
$zombie->remove();
$no_zombie = FALSE;
try {	$zombieless = new Creature( 2 ); }
catch( Exception $e ) { $no_zombie = TRUE; }
echo 'Zombie Removal Test: '.( $no_zombie ? 'PASS' : 'FAILED' )."\n";

echo "</pre>\n";