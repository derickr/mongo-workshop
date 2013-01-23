<?php
class Functions
{
	static function split_tag( $tag )
	{
		preg_match( '/^(.*)=(.*)$/', $tag, $match );
		return array( $match[1], $match[2] );
	}

	static function split_tags( array $tags )
	{
		$returnTags = array();

		foreach ( $tags as $tag )
		{
			list( $name, $value ) = self::split_tag( $tag );
			$returnTags[$name] = $value;
		}
		return $returnTags;
	}
}

class Triangle
{
	private $m;
	private $d;
	private $o;
	private $tags;
	private $fields = array();
	public  $title = "Check in";

	function __construct()
	{
		$this->m = new MongoClient( 'mongodb://localhost' );
		$this->d = $this->m->selectDb( 'demo' );
		$this->c = $this->d->poi;

		/* Find object */
		$object = isset( $_POST['object'] ) ? $_POST['object'] : $_GET['object'];
		$this->o = $this->c->findOne( array( '_id' => preg_replace( '/[^nw0-9]/', '', $object ) ) );
		$this->tags = Functions::split_tags( $this->o['tags'] );
	}

	private function add_field( $name, $widget)
	{
		if ( !array_key_exists( $name, $this->fields ) )
		{
			$this->fields[$name] = array( $name, $widget, array_key_exists( $name, $this->tags ) ? $this->tags[$name] : NULL );
		}
	}

	private function add_addres_fields()
	{
		$this->add_field( 'addr:housename', 'freeform' );
		$this->add_field( 'addr:housenumber', 'freeform' );
		$this->add_field( 'addr:street', 'freeform' );
		$this->add_field( 'addr:city', 'freeform' );
		$this->add_field( 'addr:postcode', 'freeform' );
	}

	function getLocation()
	{
		if ( $this->o['type'] == 2 )
		{
			$loc = $this->o['loc'][0];
		}
		else
		{
			$loc = $this->o['loc'];
		}

		return "lat={$loc[1]}&lon={$loc[0]}";
	}

	function run()
	{
		/* Do tests */
		if ( array_key_exists( 'amenity', $this->tags ) )
		{
			$amenity = $this->tags['amenity'];
			$this->title = "Check in into $amenity";

			$this->add_field( 'amenity', 'static' );
			$this->add_field( 'name', 'freeform' );

			if ( in_array( $amenity, array( 'restaurant' ) ) )
			{
				$this->add_field( 'cuisine', 'listCuisine' );
			}
			if ( in_array( $amenity, array( 'pub', 'bar' ) ) )
			{
				$this->add_field( 'real_ale', 'boolean' );
				$this->add_field( 'real_cider', 'boolean' );
			}
		}

		/* Add the rest of the fields */
		foreach ( $this->tags as $name => $value )
		{
			if ( !array_key_exists( $name, $this->fields ) )
			{
				$this->fields[$name] = array( $name, 'freeform', $value );
			}
		}

		$this->add_field( 'name', 'freeform' );
		$this->add_addres_fields();
	}

	private function get_cuisines()
	{
		$allWithCuisine = array(
			'$match' => array( 'tags' => new MongoRegex( '/^cuisine=/' ) )
		);
		$justTheTags = array(
			'$project' => array( 'tags' => 1 )
		);
		$unwindTags = array(
			'$unwind' => '$tags'
		);
		$groupByTags = array(
			'$group' => array(
				'_id' => '$tags',
				'count' => array( '$sum' => 1 ),
			)
		);
		$sort = array(
			'$sort' => array( '_id' => 1 )
		);

		$result = $this->c->aggregate(
			array(
				$allWithCuisine, $justTheTags, $unwindTags, $allWithCuisine,
				$groupByTags, $sort,
			)
		);

		$cuisines = array();
		foreach ( $result['result'] as $item )
		{
			list( $name, $value ) = Functions::split_tag( $item['_id'] );
			$cuisines[] = $value;
		}
		return $cuisines;
	}

	private function create_widget( $type, $name, $initValue )
	{
		$value = isset( $initValue[2] ) ? $initValue[2] : '';
		switch ( $type )
		{
			case 'static':
				return "<tr><td>$name</td><td>{$value}</td></tr>\n";

			case 'boolean':
				$ret = "<tr><td>$name</td><td><select name='{$name}'>";
				$ret .= "<option value='?'>«unknown»</option>\n";
				$ret .= "<option value='1'>yes</option>\n";
				$ret .= "<option value='0'>no</option>\n";
				$ret .= "</select></td></tr>\n";
				return $ret;

			case 'freeform':
				return "<tr><td>$name</td><td><input type='text' name='{$name}' value='{$value}'></input></td></tr>\n";

			case 'listCuisine':
				$ret = "<tr><td>$name</td><td><select name='{$name}'>";
				$ret .= "<option value='0'>«none set»</option>\n";
				foreach ( $this->get_cuisines() as $cuisine )
				{
					$selected = $value == $cuisine ? ' selected="selected"' : '';
					$ret .= "<option value='{$cuisine}'{$selected}>{$cuisine}</option>\n";
				}
				$ret .= "</select></td></tr>\n";
				return $ret;

		}
	}

	function show_data()
	{
		$ret = '';
		$ret .= "<input type='hidden' name='action' value='checkin'></input>\n";
		$ret .= "<input type='hidden' name='object' value='{$this->o['_id']}'></input>\n";
		$ret .= '<table>';
		foreach ( $this->fields as $name => $value )
		{
			$ret .= $this->create_widget( $value[1], $name, $value );
		}
		$ret .= "<tr><td colspan='2'><input type='submit' name='checkin' value='check in'></input></td></tr>\n";
		$ret .= '</table>';
		return $ret;
	}

	function commit()
	{
		$values = $_GET;
		$updates = array();
		unset( $values['action'] );
		unset( $values['object'] );
		unset( $values['checkin'] );

		foreach( $values as $key => $value )
		{
			@$values[$key] = (string) $value;

			if ( $values[$key] === '?' || $values[$key] === '' || $values[$key] === 'Array' )
			{
				continue;
			}

			if ( array_key_exists( $key, $this->tags ) )
			{
				if ( $values[$key] != $this->tags[$key] )
				{
					$updates["possible.$key"] = array( $values[$key], 0 );
				}
			}
			else
			{
				$updates["possible.$key"] = array( $values[$key], 0 );
			}
		}
		ini_set( 'xdebug.var_display_max_depth', 5 );

		$update = array( '$addToSet' => $updates );

		$this->d->poi->update( array( '_id' => $this->o['_id'] ), $update );
	}
}
?>
