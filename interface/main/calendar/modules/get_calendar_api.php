<?php

/**
 *
 * Script to find open appointment slots
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Ian Jardine ( github.com/epsdky )
 * @author    Roberto Vasquez <robertogagliotta@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2005-2013 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Stephen Waite <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once($_SERVER['DOCUMENT_ROOT'] . "/interface/globals.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/library/patient.inc");
require_once($_SERVER['DOCUMENT_ROOT'] . "/library/appointments.inc.php");
require_once($GLOBALS['incdir'] . "/main/holidays/Holidays_Controller.php");

?>

<?php
// // check access controls
// if (!acl_check('patients', 'appt', '', array('write', 'wsome'))) {
//     die(xlt('Access not allowed'));
// }

// If the caller is updating an existing event, then get its ID so
// we don't count it as a reserved time slot.
$eid = empty($_REQUEST['eid']) ? 0 : 0 + $_REQUEST['eid'];

$input_catid = $_REQUEST['catid'];

// Record an event into the slots array for a specified day.
function doOneDay($catid, $udate, $starttime, $duration, $prefcatid)
{ }

// seconds per time slot
$calInT = $GLOBALS['calendar_interval'];
$slotsecs = $GLOBALS['calendar_interval'] * 60;


$catslots = 1;
if ($input_catid) {
    $srow = sqlQuery("SELECT pc_duration FROM openemr_postcalendar_categories WHERE pc_catid = ?", array($input_catid));
    if ($srow['pc_duration']) {
        $catslots = ceil($srow['pc_duration'] / $slotsecs);
    }
}

$info_msg = "";

$searchdays = 7; // default to a 1-week lookahead
if ($_REQUEST['searchdays']) {
    $searchdays = $_REQUEST['searchdays'];
}

// Get a start date.
$sdate = ($_REQUEST['startdate']) ? DateToYYYYMMDD($_REQUEST['startdate']) : date("Y-m-d");

// Get an end date - actually the date after the end date.
preg_match("/(\d\d\d\d)\D*(\d\d)\D*(\d\d)/", $sdate, $matches);
$edate = date(
    "Y-m-d",
    mktime(0, 0, 0, $matches[2], $matches[3] + $searchdays, $matches[1])
);

// compute starting time slot number and number of slots.
$slotstime = strtotime("$sdate 00:00:00");
$slotetime = strtotime("$edate 00:00:00");
$slotbase  = (int) ($slotstime / $slotsecs);
$slotcount = (int) ($slotetime / $slotsecs) - $slotbase;

if ($slotcount <= 0 || $slotcount > 100000) {
    die(xlt("Invalid date range"));
}

$slotsperday = (int) (60 * 60 * 24 / $slotsecs);

// Compute the number of time slots for the given event duration, or if
// none is given then assume the default category duration.
$evslots = $catslots;
if (isset($_REQUEST['evdur'])) {
    // bug fix #445 -- Craig Bezuidenhout 09 Aug 2016
    // if the event duration is less than or equal to zero, use the global calander interval
    // if the global calendar interval is less than or equal to zero, use 10 mins
    if (intval($_REQUEST['evdur']) <= 0) {
        if (intval($GLOBALS['calendar_interval']) <= 0) {
            $_REQUEST['evdur'] = 10;
        } else {
            $_REQUEST['evdur'] = intval($GLOBALS['calendar_interval']);
        }
    }

    $evslots = 60 * $_REQUEST['evdur'];
    $evslots = (int) (($evslots + $slotsecs - 1) / $slotsecs);
}

// If we have a provider, search.
//
if ($_REQUEST['providerid']) {
    $providerid = $_REQUEST['providerid'];

    // Create and initialize the slot array. Values are bit-mapped:
    //   bit 0 = in-office occurs here
    //   bit 1 = out-of-office occurs here
    //   bit 2 = reserved
    // So, values may range from 0 to 7.
    //
    $slots = array_pad(array(), $slotcount, 0);

    $sqlBindArray = array();

    // Note there is no need to sort the query results.
    $query = "SELECT pc_eventDate, pc_endDate, pc_startTime, pc_duration, " .
        "pc_recurrtype, pc_recurrspec, pc_alldayevent, pc_catid, pc_prefcatid " .
        "FROM openemr_postcalendar_events " .
        "WHERE pc_aid = ? AND " .
        "pc_eid != ? AND " .
        "((pc_endDate >= ? AND pc_eventDate < ? ) OR " .
        "(pc_endDate = '0000-00-00' AND pc_eventDate >= ? AND pc_eventDate < ?))";

    array_push($sqlBindArray, $providerid, $eid, $sdate, $edate, $sdate, $edate);

    // phyaura whimmel facility filtering
    if ($_REQUEST['facility'] > 0) {
        $facility = $_REQUEST['facility'];
        $query .= " AND pc_facility = ?";
        array_push($sqlBindArray, $facility);
    }

    // end facility filtering whimmel 29apr08

    //////
    $events2 = fetchEvents($sdate, $edate, null, null, false, 0, $sqlBindArray, $query);
    foreach ($events2 as $row) {
        $thistime = strtotime($row['pc_eventDate'] . " 00:00:00");
        $catid = $row['pc_catid'];
        $udate = $thistime;
        $starttime = $row['pc_startTime'];
        $duration = $row['pc_duration'];
        $prefcatid = $row['pc_prefcatid'];

        # global $slots, $slotsecs, $slotstime, $slotbase, $slotcount, $input_catid;
        $udate = strtotime($starttime, $udate);
        if ($udate < $slotstime) {
            return;
        }

        $i = (int) ($udate / $slotsecs) - $slotbase;
        $iend = (int) (($duration + $slotsecs - 1) / $slotsecs) + $i;
        if ($iend > $slotcount) {
            $iend = $slotcount;
        }

        if ($iend <= $i) {
            $iend = $i + 1;
        }

        for (; $i < $iend; ++$i) {
            if ($catid == 2) {        // in office
                // If a category ID was specified when this popup was invoked, then select
                // only IN events with a matching preferred category or with no preferred
                // category; other IN events are to be treated as OUT events.
                if ($input_catid) {
                    if ($prefcatid == $input_catid || !$prefcatid) {
                        $slots[$i] |= 1;
                    } else {
                        $slots[$i] |= 2;
                    }
                } else {
                    $slots[$i] |= 1;
                }

                break; // ignore any positive duration for IN
            } else if ($catid == 3) { // out of office
                $slots[$i] |= 2;
                break; // ignore any positive duration for OUT
            } else { // all other events reserve time
                $slots[$i] |= 4;
            }
        }
        // doOneDay(
        //     $row['pc_catid'],
        //     $thistime,
        //     $row['pc_startTime'],
        //     $row['pc_duration'],
        //     $row['pc_prefcatid']
        // );
    }

    //////

    // Mark all slots reserved where the provider is not in-office.
    // Actually we could do this in the display loop instead.
    $inoffice = false;
    for ($i = 0; $i < $slotcount; ++$i) {
        if (($i % $slotsperday) == 0) {
            $inoffice = false;
        }

        if ($slots[$i] & 1) {
            $inoffice = true;
        }

        if ($slots[$i] & 2) {
            $inoffice = false;
        }

        if (!$inoffice) {
            $slots[$i] |= 4;
            $prov[$i] = $i;
        }
    }
}

$ckavail = true;
// If the requested date is a holiday/closed date we need to alert the user about it and let him choose if he wants to proceed
//////
$is_holiday = false;
$holidays_controller = new Holidays_Controller();
$holidays = $holidays_controller->get_holidays_by_date_range($sdate, $edate);
if (in_array($sdate, $holidays)) {
    $is_holiday = true;
    $ckavail = true;
}
$result = array();

//////
// The cktime parameter is a number of minutes into the starting day of a
// tentative appointment that is to be checked.  If it is present then we are
// being asked to check if this indicated slot is available, and to submit
// the opener and go away quietly if it is.  If it's not then we have more
// work to do.

if (isset($_REQUEST['cktime'])) {
    $cktime = 0 + $_REQUEST['cktime'];
    $ckindex = (int) ($cktime * 60 / $slotsecs);
    for ($j = $ckindex; $j < $ckindex + $evslots; ++$j) {
        if ($slots[$j] >= 4) {
            $ckavail = false;
            $isProv = false;
            if (isset($prov[$j])) {
                $isProv = 'TRUE';
            }
        }
    }
}
if (!empty($slots)) {
    $lastdate = "";
    $ampmFlag = "am"; // establish an AM-PM line break flag
    for ($i = 0; $i < $slotcount; ++$i) {
        $available = true;
        for ($j = $i; $j < $i + $evslots; ++$j) {
            if ($slots[$j] >= 4) {
                $available = false;
            }
        }

        if (!$available) {
            continue; // skip reserved slots
        }

        $utime = ($slotbase + $i) * $slotsecs;
        $thisdate = date("Y-m-d", $utime);
        if ($thisdate != $lastdate) {
            // if a new day, start a new row
            if ($lastdate) { }

            $lastdate = $thisdate;
            $dayName = date("l", $utime);
            // echo " <tr class='oneresult'>\n";
            // echo "  <td class='srDate'>" . xlt($dayName) . "<br>" . text(oeFormatSDFT($utime)) . "</td>\n";
            // echo "  <td class='srTimes'>";
            // echo "<div id='am'>AM ";
            $ampmFlag = "am";  // reset the AMPM flag

            $result[$thisdate] = array();
        }

        $ampm = date('a', $utime);
        $ampmFlag = $ampm;
        $adate = getdate($utime);
        $result[$thisdate][] = date("H:i", $utime);
        // If the duration is more than 1 slot, increment $i appropriately.
        // This is to avoid reporting available times on undesirable boundaries.
        $i += $evslots - 1;
    }
}
