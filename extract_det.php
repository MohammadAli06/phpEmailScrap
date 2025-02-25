<?php
// Email credentials
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';  
$username = 'bitmasters52@gmail.com';  
$password = 'ekgl bhpx yjth bpni';  

// $servername = "fleet.lindatours.in";
// $dbuser = "u820563802_Linda_fleet";
// $dbpass = "Fleet@1234";
// $dbname = "u820563802_Linda_fleet";

$servername = "localhost";
$dbuser = "root";
$dbpass = "";
$dbname = "u820563802_Linda_fleet";

// Connect to mailbox
$inbox = imap_open($hostname, $username, $password) or die('Cannot connect to email: ' . imap_last_error());

// Search for emails from Linda Tours
$emails = imap_search($inbox, 'FROM "info.lindatours@gmail.com"');  

if ($emails) {
    rsort($emails); // Sort latest first

    foreach ($emails as $email_number) {
        $overview = imap_fetch_overview($inbox, $email_number, 0);
        $message = imap_fetchbody($inbox, $email_number, 1.1); // HTML part

        if (empty($message)) {
            $message = imap_fetchbody($inbox, $email_number, 1); // Plain text fallback
        }

        // Extract Booking Reference
        preg_match('/Booking Reference:\s*(BR-\d+)/', $message, $bookingMatches);
        $bookingReference = $bookingMatches[1] ?? 'Not Found';

        // Extract Title (Tour Name)
        preg_match('/Tour Name:\s*(.+)/', $message, $titleMatches);
        $title = $titleMatches[1] ?? 'Not Found';

        // Extract Location
        preg_match('/Location:\s*(.*)/', $message, $locationMatches);
        $location = trim($locationMatches[1] ?? 'Not Found');

        // Extract Lead Traveler Name
        preg_match('/Lead Traveler Name:\s*(.+)/', $message, $travelerMatches);
        $leadTraveler = $travelerMatches[1] ?? 'Not Found';

        // Extract Travel Date
        preg_match('/Travel Date:\s*([\w, ]+\d{4})/', $message, $dateMatches);
        $travelDate = $dateMatches[1] ?? 'Not Found';

        // Extract Start Time from Tour Grade Code
        preg_match('/Tour Grade Code:\s*TG\d+~(\d{2}:\d{2})/', $message, $timeMatches);
        $startTime = $timeMatches[1] ?? 'Not Found';

        // Create Start Datetime
        $startDatetime = ($travelDate !== 'Not Found' && $startTime !== 'Not Found') ? "$travelDate $startTime" : 'Not Found';

        // Extract Hotel Pickup
        preg_match('/Hotel Pickup:\s*(.+)/', $message, $hotelMatches);
        $hotelPickup = $hotelMatches[1] ?? 'Not Found';

        // Extract Status (Confirmed, Amended, Cancelled)
        preg_match('/(Confirmed|Amended|Cancelled)/i', $message, $statusMatches);
        $status = $statusMatches[1] ?? 'Not Found';

        try {
            $pdo = new PDO("mysql:host=localhost;dbname=u820563802_Linda_fleet", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Check if booking already exists
            $stmt = $pdo->prepare("SELECT status FROM bookings WHERE booking_reference = ?");
            $stmt->execute([$bookingReference]);
            $existingBooking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingBooking) {
                // If existing booking is "Confirmed" or "Amended", update to "Cancelled"
                // if (($existingBooking['status'] === 'confirmed' || $existingBooking['status'] === 'Amended') && $status === 'Cancelled') {
                //     $updateStmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE booking_reference = ?");
                //     $updateStmt->execute([$status, $bookingReference]);
                //     echo "Updated Booking: $bookingReference - Status changed to Cancelled\n";
                // } else
                if ($status === 'Amended') {
                    // If status is "Amended", update it
                    $updateStmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE booking_reference = ?");
                    $updateStmt->execute([$status, $bookingReference]);
                    echo "Updated Booking: $bookingReference - Status changed to Amended\n";
                }
            } else {
                // Insert new booking with book_status = pending
                if ($status !== 'Cancelled') { // Don't insert new cancelled bookings
                    $insertStmt = $pdo->prepare("INSERT INTO bookings (booking_reference, title, location, travel_date, lead_traveler_name, start_datetime, hotel_pickup, status, book_status) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                    $insertStmt->execute([$bookingReference, $title, $location, $travelDate, $leadTraveler, $startDatetime, $hotelPickup, $status]);
                    echo "Stored Booking: $bookingReference - Status: $status, Book Status: Pending\n";
                } else {
                    echo "Skipping insertion for cancelled booking: $bookingReference\n";
                }
            }
        } catch (PDOException $e) {
            echo "Database Error: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "No emails found from Linda Tours.";
}

// Close IMAP connection
imap_close($inbox);
?>
