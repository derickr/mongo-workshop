<?php
header('Content-type: text/plain');
$m = new MongoClient( 'mongodb://localhost' );
$d = $m->selectDb( 'demo' );

$wantedD = isset($_GET['d']) ? $_GET['d']: 1;

$query = array();
/* #1a: Write a query that only shows pubs */

/* #1b: Write a query that only shows roads */

/* #1c: Write a query that only allows amenity, shop and tourism nodes and ways */

/* End */

/* This runs the geo search */
$s = $d->command(
	array(
		'geoNear' => 'poi',
		'spherical' => true,
		'near' => array(
			(float) $_GET['lon'],
			(float) $_GET['lat']
		),
		'num' => 250,
		'maxDistance' => $wantedD / 6371.01,
		'query' => $query,
	)
);

foreach( $s['results'] as $res)
{
	$o = $res['obj'];
	$ret = array(
		'type' => 'Feature',
		'properties' => array( 'popupContent' => '', 'changed' => false ),
	);
	if ( isset( $o['possible'] ) )
	{
		$ret['properties']['changed'] = true;
	}
	if ( isset( $o['tags'] ) ) {
		$name = $content = '';
		foreach ( $o['tags'] as $tagName => $value ) {
			list( $tagName, $value ) = explode( '=', $value );
			if ( $tagName == 'name' ) {
				$name = $value; 
			} else {
				$content .= "<br/>{$tagName}: {$value}\n";
			}
		}
		$content .= "<br/><form action='checkin.php' method='post'><input type='hidden' name='object' value='{$o['_id']}'/><input type='submit' value='check in'/></form>";
		$ret['properties']['popupContent'] = "<b>{$name}</b>" . $content;
	}
	if ($o['type'] == 1) {
		$ret['geometry'] = array(
			'type' => "Point",
			'coordinates' => $o['loc']
		);
	}
	if ($o['type'] == 2) {
		if ($o['loc'][0] == $o['loc'][count($o['loc']) - 1]) {
			$ret['geometry'] = array(
				'type' => "Polygon",
				'coordinates' => array($o['loc']),
			);
		} else {
			$ret['geometry'] = array(
				'type' => "LineString",
				'coordinates' => $o['loc'],
			);
		}
	}
	$rets[] = $ret;
}
echo json_encode( $rets, JSON_PRETTY_PRINT );
