<?php
    use Symfony\Component\Yaml\Yaml;
    ini_set("session.cookie_httponly", 1);
    ini_set("display_errors", 0);
    ini_set("log_errors", 1);

    define("BAIKAL_CONTEXT", true);
    define("PROJECT_CONTEXT_BASEURI", "/");

    if (file_exists(getcwd() . "/Core")) {
        # Flat FTP mode
        define("PROJECT_PATH_ROOT", getcwd() . "/");    #./
    } else {
        # Dedicated server mode
        define("PROJECT_PATH_ROOT", dirname(getcwd()) . "/");    #../
    }

    if (!file_exists(PROJECT_PATH_ROOT . 'vendor/')) {
        exit('<h1>Incomplete installation</h1><p>Ba&iuml;kal dependencies have not been installed. If you are a regular user, this means that you probably downloaded the wrong zip file.</p><p>To install the dependencies manually, execute "<strong>composer install</strong>" in the Ba&iuml;kal root folder.</p>');
    }
    require PROJECT_PATH_ROOT . 'vendor/autoload.php';

    putenv('GOOGLE_APPLICATION_CREDENTIALS=./service_account_key.json');
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST");
    header("Access-Control-Allow-Headers: Content-Type");

    // Read the request body
    $data = json_decode(file_get_contents("php://input"));

    use Mailgun\Mailgun;
    use Google\Cloud\Firestore\FirestoreClient;
    use Mailgun\HttpClient\HttpClientConfigurator;

    try {
        $firestore = new FirestoreClient();
    } catch (\Exception $e) {
        echo 'Error initializing Firestore client: ' . $e->getMessage();
        exit;
    }
    
    $propCardTemplate = file_get_contents("../Specific/propertycard.html");
    $fullEmail = file_get_contents("../Specific/da-temp.html");

    
    // Retrieve data from Firestore where 'active' is true
    try {
        $query = $firestore->collection('team_realtor_saved_searches')->where('active', '=', true);
        $documents = $query->documents();
        
        $results = [];
        foreach ($documents as $document) {
            $retriveddata = $document->data(); 
            $data = [];
            
            $data = $retriveddata;
            // Fetch additional user data from users collection based on uid
            $uid = $retriveddata['uid'];
            $userDoc = $firestore->collection('users')->document($uid)->snapshot();
            $userData = $userDoc->data(); // Retrieve user data
            
            // Merge user data with saved search data
            $data['user_data'] = $userData;
            
            // Explode search query string into individual parameters
            $searchQuery = $retriveddata['search_data'];
            $searchParams = [];
            parse_str(substr($searchQuery, 1), $searchParams); // Remove '?' from the beginning
            $data['searchParams'] = $searchParams;
            $results[] = $data;
        }
    
        // print_r($results);
        
        $apiKey = "23c8729a55e9986ae45ca71d18a3742c";
        $dataset = "mlspin";
        $limit = 20;
        $orderby = '$orderby='.urlencode('StatusChangeTimestamp desc');
        $apiURL = "https://api.bridgedataoutput.com/api/v2/odata/$dataset/Property?access_token=$apiKey";
    
        // Set timezone to UTC... OriginalEntryTimestamp setup for UTC-3 zone.
        date_default_timezone_set('UTC');
    
        $hoursAgo = 12;
        $date = date('Y-m-dTH:i:s', strtotime('-'.($hoursAgo+1).' hour'));

        foreach ($results as $result) {

            $email = $result['user_data']['email'];
            // if (str_contains($email, 'hmzamalik47')) {
            //     continue;
            // }

            // Constructing the bridgeQuery
            $bridgeQuery = "tolower(PropertyType) eq '" . strtolower($result['searchParams']['listingStatus']) . "' and tolower(StandardStatus) eq '" . strtolower($result['searchParams']['activeStatus']) . "' and contains(tolower(UnparsedAddress), tolower('" . strtolower($result['searchParams']['address']) . "'))";
            
            if ($result['searchParams']['minPriceRange'] > 0) {
                $bridgeQuery .= " and ListPrice ge " . $result['searchParams']['minPriceRange'];
            }
            if ($result['searchParams']['maxPriceRange'] > 0) {
                $bridgeQuery .= " and ListPrice le " . $result['searchParams']['maxPriceRange'];
            }
            if ($result['searchParams']['bedrooms'] > 0) {
                $bridgeQuery .= " and BedroomsTotal ge " . $result['searchParams']['bedrooms'];
            }
            if ($result['searchParams']['bathroms'] > 0) {
                $bridgeQuery .= " and BathroomsFull ge " . $result['searchParams']['bathroms'];
            }
    
            // URL encode the query part
            $encodedFilter = urlencode($bridgeQuery);
            $fullApiUrl = $apiURL . '&$filter=' . $encodedFilter . '&$top='.$limit.'&' . $orderby;
            // print($fullApiUrl);
            // Initialize cURL
            $ch = curl_init();
            print_r($fullApiUrl);
            echo "\xA";
            curl_setopt($ch, CURLOPT_URL, $fullApiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            
            // Execute the request
            $response = curl_exec($ch);
            
            // Check for errors
            if ($response === false) {
                $error_msg = curl_error($ch);
                echo 'Curl error: ' . $error_msg . '<br>';
            } else {
                // Output the response
                $properties = json_decode($response);
                $listingCount = 0;
                $propertyCardsHtml = "";
                foreach ($properties->value as $property) {
                    $originalEntryTimestamp = $property->StatusChangeTimestamp;
                    
                    if (!isValidPeriod($date, $originalEntryTimestamp)) {
                        
                        break;
                    }

                    // print_r($property);
                    
                    $address = $property->UnparsedAddress;
                    $ListPrice = $property->ListPrice;
                    $originalListPrice = $property->OriginalListPrice;
                    $BedroomsTotal = $property->BedroomsTotal;
                    $BathroomsFull = $property->BathroomsFull;
                    $MlsStatus = $property->MlsStatus;
                    if ($MlsStatus == 'Price Changed') {
                        continue;
                    }
                    $LivingArea = $property->LivingArea;

                    if ($property->Media == null) {
                        continue;
                    }
                    $countMedia = count($property->Media);
                    $photo = $property->Media[0]->MediaURL;
                    $photo1 = $property->Media[1]->MediaURL;
                    $photo2 = $property->Media[2]->MediaURL;
                    $photo3 = $property->Media[2]->MediaURL;
                    $photo4 = $property->Media[2]->MediaURL;
                    if ($countMedia >= 4) {
                        $photo3 = $property->Media[3]->MediaURL;
                        if ($countMedia >=5)
                        {
                            $photo4 = $property->Media[4]->MediaURL;
                        }
                    }

                    if($property->PropertyType == "Residential"){
                        $PropertyType = "For Sale";
                    }else if($property->PropertyType == "Residential Lease"){
                        $PropertyType = "For Rent";
                    }
                    
                    $replacedAddress = urlencode(str_replace(" ", "-", $address) . "__".$property->ListingKey);
                    $propertyCardLink = "https://teamrealtor.org/property/$replacedAddress";
                    
                    // set Title
                    $title = 'Updated to "'.$MlsStatus.'"';
                    
                    $changedPrice = '$'.shortNumber($originalListPrice - $ListPrice);
                    $replacements = [
                        '{{TITLE}}' => $MlsStatus,
                        '{{ORIGIN_PRICE}}' => '',
                        '{{CHANGED_PRICE}}' => '',
                        '{{imageUrl}}' => $photo,
                        '{{imageUrl1}}' => $photo1,
                        '{{imageUrl2}}' => $photo2,
                        '{{imageUrl3}}' => $photo3,
                        '{{imageUrl4}}' => $photo4,
                        '{{STATUS}}' => $PropertyType,
                        '{{LINK}}' => $propertyCardLink, // Replace with the actual link
                        '{{PRICE}}' => number_format($ListPrice),
                        '{{BEDS}}' =>  $BedroomsTotal == 1 ? $BedroomsTotal . " Bed"  : $BedroomsTotal . " Beds",
                        '{{BATHS}}' => $BathroomsFull == 1 ? $BathroomsFull . " Bath" : $BathroomsFull . " Baths",
                        '{{SQFT}}' => number_format($LivingArea). " Sq Ft",
                        '{{NAME}}' => $address
                    ];
                    // Replace placeholders with actual values in the property card template
                    $propertyCardHtml = str_replace(array_keys($replacements), array_values($replacements), $propCardTemplate);
                    // Append the property card HTML to the collection
                    $propertyCardsHtml .= $propertyCardHtml;

                    $listingCount = $listingCount + 1;
                    // print_r($property);
                }
                // Check if there are property cards to display

                
                if (!empty($propertyCardsHtml)) {
                    // Prepare data for the full template
                    $mSubject = 'Status Updated to '.$listingCount.' '.(($listingCount > 1)? 'Properties':'Property').' in Your Saved Search for';
                     
                    $fullTemplateReplacements = [
                        '{{searchedIn}}' => $result['searchParams']['address'],
                        '{{SUBJECT}}'      => $mSubject,
                        '{{propertyCards}}' => $propertyCardsHtml,
                        '{{SEEALLLINK}}' => $result['search_data'],
                    ];
    
                    // Replace placeholders in the full email template
                    $fullTemplate = str_replace(array_keys($fullTemplateReplacements), array_values($fullTemplateReplacements), $fullEmail);
                    
                    
                    $statusR = 'All';
                    if ($result['searchParams']['listingStatus'] == 'Residential')
                    {
                        $statusR = 'For Sale';
                    } else if ($result['searchParams']['listingStatus'] == 'Residential Lease') {
                        $statusR = 'For Rent';
                    }
                    sendDailyAlert($email, 'Status Updated for '.$result['searchParams']['address'].' ('.$statusR.')', $fullTemplate);
                    
                }

                
            }
            // Close cURL
            curl_close($ch);
        }
    
    } catch (\Exception $e) {
        echo 'Error retrieving data: ' . $e->getMessage();
        exit;
    }
    
    function isValidPeriod($before, $var) {
    
        $timeBefore = strtotime($before); // hours ago
        $timeVar    = strtotime($var); // property's time
        $difference = round(($timeVar - $timeBefore) / 3600, 2);
        if ($difference > 0) {
            return true;
        } else {
            false;
        }
    }

    function shortNumber($num) 
    {
        $units = ['', 'K', 'M', 'B', 'T'];
        for ($i = 0; $num >= 1000; $i++) {
            $num /= 1000;
        }
        return round($num, 1) . $units[$i];
    }
    
    function sendDailyAlert($toEmail, $subject, $html) {
        $mg = Mailgun::create('key-fc10e270908d140be45ea9e56cb44f0d');
    
        // Now, compose and send your message.
        // $mg->messages()->send($domain, $params);
    
    
        // date_default_timezone_set('US/Eastern');
        // $currenttime = date('h:i:s');
    
        $params = [
            'from'    => 'TeamRealtor <postmaster@shop.mlsassistant.com>',
            'to'      => $toEmail,
            'subject' => $subject,
            'html'    => $html,
        ];
        if ($mg->messages()->send('shop.mlsassistant.com', $params)) {
            echo 'Sent to '.$toEmail;
            echo "\xA";
            // exit(0);
        } else 
            echo 'An error has occurred';
    }

?>