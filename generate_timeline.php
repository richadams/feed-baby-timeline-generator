<?php
// @author Rich Adams <https://richadams.me>

// This parses a CSV export from Feed Baby app, in order to render a full timeline image.
// Copy your CSV files into a directory called "data", and this script should do the rest.
// There are some customization options. You may or may not break things by changing them.

// Feed Baby Config
$_configFiles = array(
  "sleeps"     => "data/sleeps.csv",
  "feeds"      => "data/feeds.csv",
  "excretions" => "data/excretions.csv"
);
$_dateFormat   = 'H:i:s m-d-Y'; // Format used in the CSV.

// Data Config
$_forceDate    = false; // Set this to new DateTime('YYYY-MM-DD') if you only want up to certain date.

// Output Image Config
$_dayWidth     = 20;     // Pixels each day takes up in width.
$_yOffset      = 50;     // Pixels width for y-axis labels.
$_xOffset      = 50;     // Pixels height for x-axis labels.
$_labels       = true;   // Adds labels for every 6 hours, and every 4 weeks.
$_nightZone    = false;  // Adds a semi-transparent red zone between the hours of 9pm to 6am.
$_hourMarkers  = true;   // Adds a horizontal white line to mark at 6 hour intervals.
$_dayMarkers   = true;   // Adds a 1px separation between each day of data.
$_weekMarkers  = false;  // Adds a vertical white line to mark each new week.
$_4WeekMarkers = true;   // Adds a vertical white line to mark every 4 weeks (~month).

// Colors
// Specify as array of R,G,B
$_colors = array(
  "background" => array(31, 31, 31),     // Grey
  "sleep"      => array(238, 224, 83),   // Yellow
  "feed"       => array(40, 133, 197),   // Blue
  "excretion"  => array(159,96, 78),     // Brown
  "night"      => array(255, 0, 0),      // Red
  "marker"     => array(255, 255, 255),  // White
  "text"       => array(255, 255, 255)   // White
);

// If no labels are showing, then reset the offsets to 0, as not required.
if (!$_labels)
{
  $_yOffset = 0;
  $_xOffset = 0;
}

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

// Process a data set and set the pixels in an image.
function processData($im, $dataSet, $color)
{
  global $_dayWidth, $_xOffset, $_yOffset, $_birthDate;
  
  foreach ($dataSet as $record)
  {
    if ($record["start"] === false) { continue; }
  
    // x-axis is day-width wide, for the day it started.
    $xStart = $_birthDate->diff($record["start"])->days * $_dayWidth;
    $xEnd   = $xStart + $_dayWidth;
    
    // y-axis starts at exact minute, and ends + duration
    $yStart = floor(($record["start"]->getTimestamp() % 86400) / 60); // Get remainder of seconds in day, /60 for minutes.
    $yEnd   = $yStart + $record["duration"];
    
    // Draw the rectangle. 
    imagefilledrectangle($im, $_xOffset + $xStart, $_yOffset + $yStart, $_xOffset + $xEnd, $_yOffset + $yEnd, $color);
    
    // If it was stopped on the next day, we need to draw the bit that's in the next day
    $startDate = new DateTime($record["start"]->format('Y-m-d'));
    $endDate = new DateTime($record["stop"]->format('Y-m-d'));
    $diff = $startDate->diff($endDate, true);
    if ($diff->days > 0)
    {
      // Shift x-axis coords over to the next day.
      $xStart += $_dayWidth;
      $xEnd   += $_dayWidth;
    
      // Calculate the overlap in minutes, starting at midnight.
      $yStart = 0;
      $yEnd   = floor(($record["stop"]->getTimestamp() % 86400) / 60); // Get remainder of seconds in day, /60 for minutes.

      // Draw it.
      imagefilledrectangle($im, $_xOffset + $xStart, $_yOffset + $yStart, $_xOffset + $xEnd, $_yOffset + $yEnd, $color);
    }
  }
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
    "duration" => $data[7]
  );
});
$excretions = loadData($_configFiles["excretions"], function($data)
{
  global $_dateFormat;
  return array(
    "start"    => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "stop"     => DateTime::createFromFormat($_dateFormat, $data[1], new DateTimeZone("UTC")),
    "duration" => 1
  );
});

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Calculate image dimensions

// Get the oldest date we have data for. Consider this the birth date.
// Get the latest date we have data for. Consider this "today".
list($_birthDate, $_todayDate) = getFirstAndLastDates(array($sleeps, $feeds, $excretions));

// Force last date if user wants us to.
if ($_forceDate !== false) { $_todayDate = $_forceDate; }

// Get the number of days we're going to be using in our timeline image.
$_numberOfDays = $_birthDate->diff($_todayDate)->days + 1; // +1 because inclusive of end day.

// Width per day, multiplied by number of days.
$width = $_xOffset + ($_numberOfDays * $_dayWidth);
 
// Each row is a minute, so total minutes in a day.
$height = $_yOffset + (60 * 24); // 1440

// Create a blank image of the correct dimensions.
$im = imagecreatetruecolor($width, $height);

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Allocate colors

$clrBackground = imagecolorallocate($im, $_colors["background"][0], $_colors["background"][1], $_colors["background"][2]);
$clrSleep      = imagecolorallocate($im, $_colors["sleep"][0], $_colors["sleep"][1], $_colors["sleep"][2]);
$clrFeed       = imagecolorallocate($im, $_colors["feed"][0], $_colors["feed"][1], $_colors["feed"][2]);
$clrExcretion  = imagecolorallocate($im, $_colors["excretion"][0], $_colors["excretion"][1], $_colors["excretion"][2]);
$clrNight      = imagecolorallocatealpha($im, $_colors["night"][0], $_colors["night"][1], $_colors["night"][2], 100);
$clrMarker     = imagecolorallocate($im, $_colors["marker"][0], $_colors["marker"][1], $_colors["marker"][2]);
$clrText       = imagecolorallocate($im, $_colors["text"][0], $_colors["text"][1], $_colors["text"][2]);

// Fill the background color to start with
imagefill($im, 0, 0, $clrBackground);

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Process the data!

output("Creating timeline image...");
processData($im, $sleeps, $clrSleep);
processData($im, $feeds, $clrFeed);
processData($im, $excretions, $clrExcretion);

////////////////////////////////////////////////////////////////////////////////////////////////////
// Labels and markers

// Draw day lines
if ($_dayMarkers)
{
  foreach (range(1, $_numberOfDays) as $dy)
  {
    $xPos = $dy * $_dayWidth;
    imagefilledrectangle($im, $_xOffset + $xPos, $_yOffset, $_xOffset + $xPos + 1, $height, $clrBackground);
  }
}

// Draw on the "night zone", a semi-transparent layer between 9pm and 6am.
if ($_nightZone)
{
  imagefilledrectangle($im, 0, 0, $width, 360, $clrNight); // Midnight to 6am.
  imagefilledrectangle($im, 0, 1260, $width, $height, $clrNight); // 9pm to Midnight.
}

// Draw hour lines
if ($_hourMarkers)
{
  foreach (range(0, 18, 6) as $hr)
  {
    $yPos = $hr * 60;
    imagefilledrectangle($im, 0, $_yOffset + $yPos, $width, $_yOffset + $yPos + 1, $clrMarker);
  }
}

// Draw week lines
if ($_weekMarkers)
{
  foreach (range(1, floor($_numberOfDays/7)) as $wk)
  {
    $xPos = $wk * 7 * $_dayWidth;
    imagefilledrectangle($im, $_xOffset + $xPos, 0, $_xOffset + $xPos + 2, $height, $clrMarker);
  }
}

// Draw 4wk markers
if ($_4WeekMarkers)
{
  foreach (range(0, floor(($_numberOfDays/7)/4)) as $wk)
  {
    $xPos = $wk * 7 * 4 * $_dayWidth;
    imagefilledrectangle($im, $_xOffset + $xPos, 0, $_xOffset + $xPos + 2, $height, $clrMarker);
  }
}

if ($_labels)
{
  // Time labels
  foreach (range(0, 18, 6) as $hr)
  {
    imagettftext($im, 30, 90, 40, $_yOffset + ($hr * 60) + 115,  $clrText, './arial.ttf', sprintf('%02d', $hr).':00');
  }

  // Week labels
  foreach (range(0, floor($_numberOfDays/7), 4) as $wk)
  {
    imagettftext($im, 30, 0, $wk * 7 * $_dayWidth + 60,  40, $clrText, './arial.ttf', ($wk == 0) ? 'Birth' : 'Week '.$wk);
  }
}

////////////////////////////////////////////////////////////////////////////////////////////////////
// Finally, render the image.

// Save the image!
imagepng($im, "timeline.png");

// Free up the memory
imagedestroy($im);

// We're done!
output("timeline.png written, processing complete");
