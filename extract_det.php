<?php
// Email credentials
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';  
$username = 'bitmasters52@gmail.com';  
$password = 'ekgl bhpx yjth bpni';  

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

        // Extract Status (Confirmed, Amended, Cancelled)
        preg_match('/(Confirmed|Amended|Cancelled)/i', $message, $statusMatches);
        $status = trim($statusMatches[1] ?? 'Not Found');

        // Extract Booking Reference
        preg_match('/Booking Reference:\s*(#?BR-\d+)/', $message, $bookingMatches);
        $bookingReference = trim($bookingMatches[1] ?? 'Not Found');

        if (strpos($message, 'Amended') !== false) {
            // Extract Title when status is "Amended" (appears after "Amended")
            preg_match('/Amended\s*\n(.*)/', $message, $titleMatches);
        } else {
            // Extract Title when status is not "Amended" (appears after "Booking Reference")
            preg_match('/Tour Name:\s*(.+)/', $message, $titleMatches);
        }
        $title = trim($titleMatches[1] ?? 'Not Found');
        
        // Extract Location
        preg_match('/Location:\s*(.+)/', $message, $locationMatches);
        $location = trim($locationMatches[1] ?? 'Not Found');

        // Extract Lead Traveler Name
        preg_match('/Lead traveler name:\s*(.+)/i', $message, $travelerMatches);
        $leadTraveler = trim($travelerMatches[1] ?? 'Not Found');

        // Extract Travel Date
        preg_match('/Travel Date:\s*([\w, ]+\d{4})/', $message, $dateMatches);
        $travelDate = trim($dateMatches[1] ?? 'Not Found');

        // Extract Start Time (If present)
        preg_match('/Tour Grade Code:\s*TG\d+~(\d{2}:\d{2})/', $message, $timeMatches);
        $startTime = trim($timeMatches[1] ?? 'Not Found');

        // Create Start Datetime
        $startDatetime = ($travelDate !== 'Not Found' && $startTime !== 'Not Found') ? "$travelDate $startTime" : 'Not Found';

        // Extract Hotel Pickup Location
        preg_match('/Hotel Pickup:\s*(.+)/', $message, $hotelMatches);
        $hotelPickup = trim($hotelMatches[1] ?? 'Not Found');

        // Extract Phone
        preg_match('/\(Alternate Phone\)\s*([A-Z]{2,}\+\d{1,4}[\s\-]?\(?\d{2,3}\)?[\s\-]?\d{3,4}[\s\-]?\d{3,4})/', $message, $phoneMatches);
        $phone = $phoneMatches[1] ?? 'Not Found';

        try {
            $pdo = new PDO("mysql:host=localhost;dbname=u820563802_Linda_fleet", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Check if booking already exists
            $stmt = $pdo->prepare("SELECT status, book_status FROM bookings WHERE booking_reference = ?");
            $stmt->execute([$bookingReference]);
            $existingBooking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingBooking) {
                // If existing booking is "Confirmed" or "Amended", update to "Cancelled"
                if (($existingBooking['status'] === 'Confirmed' || $existingBooking['status'] === 'Amended') && $status === 'Cancelled') {
                    $updateStmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE booking_reference = ?");
                    $updateStmt->execute([$status, $bookingReference]);
                    echo "Updated Booking: $bookingReference - Status changed to Cancelled\n";
                } elseif ($status === 'Amended') {
                    // Fetch existing booking details
                    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_reference = ?");
                    $stmt->execute([$bookingReference]);
                    $existingBooking = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingBooking) {
                        // Use old values if new ones are "Not Found"
                        $title = ($title !== 'Not Found') ? $title : $existingBooking['title'];
                        $location = ($location !== 'Not Found') ? $location : $existingBooking['location'];
                        $travelDate = ($travelDate !== 'Not Found') ? $travelDate : $existingBooking['travel_date'];
                        $leadTraveler = ($leadTraveler !== 'Not Found') ? $leadTraveler : $existingBooking['lead_traveler_name'];
                        $startDatetime = ($startDatetime !== 'Not Found') ? $startDatetime : $existingBooking['start_datetime'];
                        $hotelPickup = ($hotelPickup !== 'Not Found') ? $hotelPickup : $existingBooking['hotel_pickup'];
                        $phone = ($phone !== 'Not Found') ? $phone : $existingBooking['phone'];
                        $bookStatus = $existingBooking['book_status'] ?? 'Pending'; // Retain old book_status

                        // Update booking with new values
                        $updateStmt = $pdo->prepare("UPDATE bookings 
                        SET title = ?, location = ?, travel_date = ?, lead_traveler_name = ?, hotel_pickup = ?, status = ?, start_datetime = ?, book_status = ?, phone = ? 
                        WHERE booking_reference = ?");
                        $updateStmt->execute([$title, $location, $travelDate, $leadTraveler, $hotelPickup, $status, $startDatetime, $bookStatus, $phone, $bookingReference]);

                        echo "Updated Booking: $bookingReference - Status changed to Amended\n";
                    }
                }
            } else {
                // Insert new booking with book_status = "Pending"
                if ($status !== 'Cancelled') { // Don't insert new cancelled bookings
                    $bookStatus = "Pending"; // Always set book_status to Pending for new entries
                    
                    $insertStmt = $pdo->prepare("INSERT INTO bookings 
                    (booking_reference, title, location, travel_date, lead_traveler_name, hotel_pickup, status, start_datetime, book_status, phone) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->execute([$bookingReference, $title, $location, $travelDate, $leadTraveler, $hotelPickup, $status, $startDatetime, $bookStatus, $phone]);                

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
