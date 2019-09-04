<?php

/* -------------------------------------------------------------------------------------------------------- */

/**
 * THIS SCRIPT IS A MODIFIED COPY OF /INTERFACE/MAIN/CALENDAR/FIND_APPT_POPUP.PHP
 * @author DANIEL JIMÉNEZ
 * 
 * Changes:
 * 
 * No HTML, No JS
 * Relative paths to Absolute paths (In requires)
 * The content of doOneDay function has been move to events loop for a global variables issue (Line 220)
 * Returns: 
 * $result = [
 *      'date' => [
 *         'datetime',
 *         'datetime',
 *         'datetime',
 *      ]
 *      'date' => [
 *         'datetime',
 *         'datetime',
 *         'datetime',
 *      ]
 * ]
 * 
 */

/**
 * Id of inoffice event for search avaliable doctors
 * @author Daniel Jiménez
 */
define('ID_IN_OFFICE_EVENT', 2);

/* -------------------------------------------------------------------------------------------------------- */

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
require_once($_SERVER['DOCUMENT_ROOT'] . "/interface/main/holidays/Holidays_Controller.php");

// If the caller is updating an existing event, then get its ID so
// we don't count it as a reserved time slot.
$eid = empty($_REQUEST['eid']) ? 0 : 0 + $_REQUEST['eid'];

$input_catid = $_REQUEST['catid'];

// seconds per time slot
$catslots = 1;
$slotsecs = $GLOBALS['calendar_interval'] * 60;
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


/**
 * If we are looking for any provider we search all providers by facility or speciality (speciality in dev)
 * @author Daniel Jiménez
 */
$provsId = array();
$providerId = null;
$listProvidersId = array();
$listFacilitiesId = array();
if (empty($_REQUEST['providerid'])) {

    if (!empty($_REQUEST['specialityid'])) {
        $provsId = getProvidersBySpeciality($_REQUEST['specialityid']);
    }

    $sqlBindArray = array();
    $query = "SELECT pc_eventDate, pc_aid, pc_facility, pc_endDate, pc_startTime, pc_duration, " .
        "pc_recurrtype, pc_recurrspec, pc_alldayevent, pc_catid, pc_prefcatid " .
        "FROM openemr_postcalendar_events " .
        "WHERE pc_eid != ? AND " .
        "((pc_endDate >= ? AND pc_eventDate < ? ) OR " .
        "(pc_endDate = '0000-00-00' AND pc_eventDate >= ? AND pc_eventDate < ?))";

    $query .= " AND pc_catid = " . ID_IN_OFFICE_EVENT;

    array_push($sqlBindArray, $eid, $sdate, $edate, $sdate, $edate);

    // phyaura whimmel facility filtering
    if (!empty($_REQUEST['facility']) && $_REQUEST['facility'] > 0) {
        $facility = $_REQUEST['facility'];
        $query .= " AND pc_facility = ?";
        array_push($sqlBindArray, $facility);
    }

    if (!empty($provsId)) {
        $query .= " AND pc_aid IN(" . implode(',', $provsId) . ")";
    }

    /**
     * we are just looking for the provider id and the facility
     * @author Daniel Jiménez
     */
    $query .= " GROUP BY pc_aid ORDER BY pc_aid";

    $doctorsList = fetchEvents($sdate, $edate, null, null, false, 0, $sqlBindArray, $query);
    foreach ($doctorsList as $row) {
        $listProvidersId[$row['pc_aid']] = $row['pc_aid'];
    }
} else {
    $providerId = $_REQUEST['providerid'];
    $listProvidersId = array($providerId);
}


/**
 * Indexing events and slots by provider
 * @author Daniel Jiménez
 */
$slotsByProvider = array();
$eventsListByProvider = array();

// Note there is no need to sort the query results. => Change, order is needed -DJ
$query = "SELECT pc_eventDate, pc_eid, pc_aid, pc_facility, pc_endDate, pc_startTime, pc_duration, " .
    "pc_recurrtype, pc_recurrspec, pc_alldayevent, pc_catid, pc_prefcatid " .
    "FROM openemr_postcalendar_events " .
    "WHERE pc_eid != ? AND " .
    "((pc_endDate >= ? AND pc_eventDate < ? ) OR " .
    "(pc_endDate = '0000-00-00' AND pc_eventDate >= ? AND pc_eventDate < ?))";

/**
 * $sqlBind reboot
 * @author Daniel Jiménez
 */
$sqlBindArray = array();
array_push($sqlBindArray, $eid, $sdate, $edate, $sdate, $edate);

/**
 * Change if we want search for any provider
 * @author Daniel Jiménez
 */
if (count($listProvidersId) > 0) {
    $query .= " AND pc_aid IN (" . implode(',', $listProvidersId) .  ") ";
}

// phyaura whimmel facility filtering
if (!empty($_REQUEST['facility']) && $_REQUEST['facility'] > 0) {
    $facility = $_REQUEST['facility'];
    $query .= " AND pc_facility = ?";
    array_push($sqlBindArray, $facility);
}
// end facility filtering whimmel 29apr08

$query .= " ORDER BY pc_aid ";
//////

/**
 * We only make one query for all doctors events indexed by doctor id
 * @author Daniel Jiménez
 */
$events2 = fetchEvents($sdate, $edate, null, null, false, 0, $sqlBindArray, $query);
foreach ($events2 as $row) {
    $eventsListByProvider[$row['pc_aid']][$row['pc_eid']] = $row;
    $listFacilitiesId[$row['pc_aid']] = $row['pc_facility'];
}


/**
 * We make the process foreach doctor
 * @author Daniel Jiménez
 */
foreach ($listProvidersId as $providerId) {

    // Create and initialize the slot array. Values are bit-mapped:
    //   bit 0 = in-office occurs here
    //   bit 1 = out-of-office occurs here
    //   bit 2 = reserved
    // So, values may range from 0 to 7.
    //
    $slots = array_pad(array(), $slotcount, 0);

    /**
     * The previously logic but foreach doctor and his own events 
     * Inside this foreach is the doOneDay function content
     * @author Daniel Jiménez
     */
    foreach ($eventsListByProvider[$providerId] as $row) {

        $thistime = strtotime($row['pc_eventDate'] . " 00:00:00");
        $catid = $row['pc_catid'];
        $udate = $thistime;
        $starttime = $row['pc_startTime'];
        $duration = $row['pc_duration'];
        $prefcatid = $row['pc_prefcatid'];

        // global $slots, $slotsecs, $slotstime, $slotbase, $slotcount, $input_catid;
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
                        $slots[$i] |= 1; // here (i think)
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
        // doOneDay($row['pc_catid'],$thistime,$row['pc_startTime'],$row['pc_duration'],$row['pc_prefcatid']);
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

    /**
     * Slots indexing by each doctor
     * @author Daniel Jiménez
     */
    $slotsByProvider[$providerId] = $slots;
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

/**
 * Counters init
 * @author Daniel Jiménez
 */

$amCounters = 0;
$pmCounters = 0;

$amLimit = empty($_REQUEST['amLimit']) ? 50 : $_REQUEST['amLimit'];
$pmLimit = empty($_REQUEST['pmLimit']) ? 50 : $_REQUEST['pmLimit'];

if (!empty($slotsByProvider)) {
    foreach ($slotsByProvider as $providerId => $slots) {
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

            $ampm = date('a', $utime);
            if ($ampm == 'am') {
                $amCounters++;
                if ($amCounters >= $amLimit) {
                    break;
                }
            } else {
                $pmCounters++;
                if ($pmCounters >= $pmLimit) {
                    break;
                }
            }

            if ($amCounters >= $amLimit && $pmCounters >= $pmLimit) {
                break;
            }

            $utime = ($slotbase + $i) * $slotsecs;
            $thisdate = date("Y-m-d", $utime);
            $adate = getdate($utime);
            $result[$thisdate][date("Y-m-d H:i", $utime)][$providerId] = $listFacilitiesId[$providerId];
            // If the duration is more than 1 slot, increment $i appropriately.
            // This is to avoid reporting available times on undesirable boundaries.
            $i += $evslots - 1;
        }
    }

    /**
     * Order results by date by datetime
     * @author Daniel Jiménez
     */
    ksort($result);
    foreach ($result as $date => $datetimes) {
        ksort($datetimes);
        $result[$date] = $datetimes;
    }
}
