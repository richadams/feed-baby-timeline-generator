<?php
// @author Rich Adams <https://richadams.me>

// This parses a CSV export from Feed Baby app, in order to produce some statistics.
// Copy your CSV files into a directory called "data", and this script should do the rest.
// There are some customization options. You may or may not break things by changing them.

// Feed Baby Config
$_configFiles = array(
  "sleeps"     => "data/sleeps.csv",
  "feeds"      => "data/feeds.csv",
  "excretions" => "data/excretions.csv",
  "pumpings"   => "data/pumpings.csv"
);
$_dateFormat   = 'H:i:s m-d-Y'; // Format used in the CSV.

$_forceDate    = false; // Set this to new DateTime('YYYY-MM-DD') if you only want up to certain date.

////////////////////////////////////////////////////////////////////////////////////////////////////
// Helper functions

// Outputs stuff to the user
function output($text)
{
  echo $text . "\n";
}

// Returns the date of the oldest data point and newest data point.
// Need to do a full scan, O(n). Lame.
function getFirstAndLastDates($dataSets)
{
  $oldestDate = new DateTime();
  $newestDate = new DateTime("1970-1-1");
  foreach ($dataSets as $dataSet)
  {
    foreach ($dataSet as $record)
    {
      if ($record["start"] === false) { continue; }
      if ($record["start"] < $oldestDate) { $oldestDate = clone $record["start"]; }
      if ($record["start"] > $newestDate) { $newestDate = clone $record["start"]; }
    }
  }

  // Remove time component of these dates, as we want to work from midnight for them.
  $oldestDate->setTime(0,0,0);
  $newestDate->setTime(0,0,0);

  return array($oldestDate, $newestDate);
}

// Loads in the relevant data, needs a "duration" offset.
// Returns structured array.
function loadData($file, $parseFunction)
{
  $array = array();
  if (($handle = fopen($file, "r")) !== false)
  {
    while (($data = fgetcsv($handle, 1000, ",")) !== false)
    {
      if ($data[0] == 'id') { continue; } // Skip headings.
      $array[] = $parseFunction($data);
    }
    fclose($handle);
  }
  return $array;
}

// Helper function to format time from minutes to days/minutes, etc.
function time_format($minutes)
{
  $dtF = new DateTime('@0');
  $dtT = new DateTime('@'.($minutes * 60));
  return $dtT->diff($dtF)->format('%ad:%hh:%im') . ' ('.number_format($minutes).' mins)';
}

// Process a data set and collect some basic statistics.
function processData($dataSet)
{
  global $_todayDate;

  $stats = array();
  foreach ($dataSet as $record)
  {
    if ($record["start"] === false) { continue; } // No start time, bad line.
    if ($record["start"] > $_todayDate) { continue; } // Don't go past today date.

    @$stats['total']++;
    @$stats['duration'] += $record['duration'];
    if (isset($record['quantity'])) { @$stats['quantity'] += $record['quantity']; }

    // Type
    if (!isset($record['type'])) { continue; }
    @$stats['type'][$record['type']]['total']++;
    @$stats['type'][$record['type']]['duration'] += $record['duration'];
    if (isset($record['quantity'])) { @$stats['type'][$record['type']]['quantity'] += $record['quantity']; }

    // Sub-type
    if (!isset($record['subtype']) || $record['subtype'] == "") { continue; }
    @$stats['type'][$record['type']]['subtype'][$record['subtype']]['total']++;
    @$stats['type'][$record['type']]['subtype'][$record['subtype']]['duration'] += $record['duration'];
    if (isset($record['quantity'])) { @$stats['type'][$record['type']]['subtype'][$record['subtype']]['quantity'] += $record['quantity']; }
  }

  return $stats;
}

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Load our input data sets

output("Loading data from exported files...");
$sleeps = loadData($_configFiles["sleeps"], function($data)
{
  global $_dateFormat;
  return array(
    "start"    => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "stop"     => DateTime::createFromFormat($_dateFormat, $data[2], new DateTimeZone("UTC")),
    "duration" => $data[4]
  );
});
$feeds = loadData($_configFiles["feeds"], function($data)
{
  global $_dateFormat;
  return array(
    "start"    => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "stop"     => DateTime::createFromFormat($_dateFormat, $data[2], new DateTimeZone("UTC")),
    "type"     => $data[3],
    "subtype"  => $data[10],
    "quantity" => $data[4], // oz
    "duration" => $data[7]
  );
});
$excretions = loadData($_configFiles["excretions"], function($data)
{
  global $_dateFormat;
  return array(
    "start"    => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "stop"     => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "type"     => $data[2],
    "duration" => 1
  );
});
$pumpings = loadData($_configFiles["pumpings"], function($data)
{
  global $_dateFormat;
  return array(
    "start"    => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "stop"     => DateTime::createFromFormat($_dateFormat, $data[7], new DateTimeZone("UTC")),
    "type"     => $data[2],
    "quantity" => ($data[3] / 29.57), // convert ml to oz
    "duration" => $data[9]
  );
});

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Calculate thresholds.

// Get the oldest date we have data for. Consider this the birth date.
// Get the latest date we have data for. Consider this "today".
list($_birthDate, $_todayDate) = getFirstAndLastDates(array($sleeps, $feeds, $excretions));

// Force last date if user wants us to.
if ($_forceDate !== false) { $_todayDate = $_forceDate; }

// Get the number of days we're going to be using in our timeline image.
$_numberOfDays = $_birthDate->diff($_todayDate)->days + 1; // +1 because inclusive of end day.

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Process the data!

output("From ".$_birthDate->format("Y-M-d")." To ".$_todayDate->format("Y-M-d"));

output("Generating statistics...");
$statsSleep    = processData($sleeps);
$statsFeeds    = processData($feeds);
$statsPoops    = processData($excretions);
$statsPumpings = processData($pumpings);

function outputBreakdown($stats)
{
  output(" Total #: ".number_format($stats['total']));
  output(" Duration: ".time_format($stats['duration']));
  if (isset($stats['quantity'])) { output(" Quantity: ".number_format($stats['quantity'])."oz (".number_format($stats['quantity'] * 29.57)."ml)"); }

  if (!isset($stats['type'])) { return; }
  foreach ($stats['type'] as $type => $info)
  {
    output("  - ".$type);
    output("      Total #: ".number_format($info['total']));
    output("      Duration: ".time_format($info['duration']));
    if (isset($stats['quantity']))
    {
      output("      Quantity: ".number_format($info['quantity'])."oz (".number_format($info['quantity'] * 29.57)."ml)");
    }

    if (!isset($info['subtype'])) { continue; }
    foreach ($info['subtype'] as $subtype => $subinfo)
    {
      output("     - ".$subtype);
      output("         Total #: ".number_format($subinfo['total']));
      output("         Duration: ".time_format($subinfo['duration']));
      if (isset($stats['quantity']))
      {
        output("         Quantity: ".number_format($subinfo['quantity'])."oz (".number_format($subinfo['quantity'] * 29.57)."ml)");
      }
    }
  }
}

output("-- Sleeps");
outputBreakdown($statsSleep);

output("-- Feeds");
outputBreakdown($statsFeeds);

output("-- Diapers");
outputBreakdown($statsPoops);

output("-- Pumpings");
outputBreakdown($statsPumpings);
