<?php

require('httpful.phar');

$debug = false;

if ($debug) echo "<p>debug enabled</p>\n";

// variable definitions

  $definitionId = 'Terms_of_Service'; // from PingDirectory
  $pingFederateHost = 'pingfederate.example.com:9031'; // hostname:engine_port
  $pingFederateUser = 'ConsentAdapter'; // PingFederate agentless adapter username
  $pingFederatePass = '2FederateM0re'; // PingFederate agentless adapter password
  $pingDirectoryHost = 'pingdirectory.example.com:8443'; // hostname:http_port
  $pingDirectoryUser = 'cn=Directory Manager'; // PingDirectory user with ability to see consents
  $pingDirectoryPass = '2FederateM0re'; // PingDirectory password

  // to make the code more readable, there are several PATHS defined

  // ALL - Every time the adapter fires, this should happen
  // UNKNOWN - We do not know about this user yet.  Once identified they will be NEW or EXISTING
  // NEW - This is a new consent approval
  // EXISTING - User has an existing consent approval
  // NONE - This is an error state that should not occur

  // ===================================================

  // ALL - get stuff from form post

  $referenceId = $_POST['REF'];
  $resumePath = $_POST['resumePath'];

  date_default_timezone_set('UTC');
  $timestamp = date("F j, Y, g:i a");

  if ($debug) echo "<p>$referenceId</p>\n";
  if ($debug) echo "<p>$resumePath</p>\n";

  // reusable function to hand user back to pingfederate

  function handoff($pingFederateHost, $pingFederateUser, $pingFederatePass, $resumePath, $entryUUID) {
    
    $url = "https://" . $pingFederateHost . "/ext/ref/dropoff";

    $response = \Httpful\Request::post($url)
    ->authenticateWith($pingFederateUser, $pingFederatePass)
    ->sendsJson()
    ->body(['subject' => $entryUUID])
    ->send();

    $referenceId = "{$response->body->REF}";

    $url = "https://" . $pingFederateHost . $resumePath . "?REF=" . $referenceId;

    header ("Location: " . $url);
  }

  // NONE - get rid of people who show up without referenceId or resumePath

  if (! $referenceId || ! $resumePath) {
    header ('Location: https://www.example.com');
  }

  // NEW - was the consent form just submit?

  if (isset($_POST['acceptConsent']) && $_POST['acceptConsent'] == 'True') {

    $entryUUID = $_POST['entryUUID'];
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $version = $_POST['version'];

    // record acceptance

    $url = "https://" . $pingDirectoryHost . "/consent/v1/consents";

    // this is the payload that will be written to track the new consent

    $json = json_encode(array(
      'status' => 'accepted',
      'subject' => $entryUUID,
      'actor' => $entryUUID,
      'audience' => $definitionId,
      'definition' => array(
        'id' => $definitionId,
        'version' => $version,
        'locale' => 'en'
      ),
      'dataText' => 'dataText',
      'purposeText' => 'purposeData',
      'data' => array(
        'timestamp' => $timestamp,
      ),
      'consentContext' => array(
        'captureMethod' => $definitionId,
        'subject' => array (
          'userAgent' => $userAgent,
          'ipAddress' => $ipAddress
        )
      )
    ));

    $response = \Httpful\Request::post($url)
    ->authenticateWith($pingDirectoryUser, $pingDirectoryPass)
    ->sendsJson()
    ->body($json)
    ->send();

    $status = "{$response->body->status}";

    if ($status == 'accepted') {
      handoff($pingFederateHost, $pingFederateUser, $pingFederatePass, $resumePath, $entryUUID);
    } else {
      //bad news bears
      exit();
    }

  } else {

    // UNKNOWN - query pingfederate for this referenceId
    
    $url = "https://" . $pingFederateHost . "/ext/ref/pickup?REF=" . $referenceId;

    $response = \Httpful\Request::get($url)
      ->authenticateWith($pingFederateUser, $pingFederatePass)
      ->expectsJson()
      ->send();

    $entryUUID = "{$response->body->{'chainedattr.entryUUID'}}";

    if (! $entryUUID) {
      // NONE - should not happen.  Possible that the referenceId has expired.
      if ($debug) echo "<p>No entryUUID returned.  Unable to proceed.</p>\n";
      exit();
    }

    // UNKNOWN - look in pingdirectory for current consent

    $url = "https://" . $pingDirectoryHost . "/consent/v1/consents?actor=" . $entryUUID;

    $response = \Httpful\Request::get($url)
    ->authenticateWith($pingDirectoryUser, $pingDirectoryPass)
    ->expectsJson()
    ->send();

    $responseCount = "{$response->body->size}";
    
    // UNKNOWN - iterate thru existing consents to find any

    for ($x = 0; $x < $responseCount; $x = $x + 1) {

      $status = "{$response->body->_embedded->consents[$x]->status}";
      $version = "{$response->body->_embedded->consents[$x]->definition->version}";
      $currentVersion = "{$response->body->_embedded->consents[$x]->definition->currentVersion}";

      if ($status == 'accepted' && $version == $currentVersion) {

        // EXISTING - the consent is active and matches the current version of the definition

        handoff($pingFederateHost, $pingFederateUser, $pingFederatePass, $resumePath, $entryUUID);

      }

    }

    // NEW - the user does not have a consent. look up definition

    $url = "https://" . $pingDirectoryHost . "/consent/v1/definitions/" . $definitionId . "/localizations/en";

    $response = \Httpful\Request::get($url)
    ->authenticateWith($pingDirectoryUser, $pingDirectoryPass)
    ->expectsJson()
    ->send();

    $titleText = "{$response->body->titleText}";
    $dataText = "{$response->body->dataText}";
    $purposeText = "{$response->body->purposeText}";
    $version = "{$response->body->version}";

    if (! $titleText || ! $dataText || ! $purposeText || ! $version) {

      if ($debug) echo "<p>There are no consent definitions.  Redirect to PingFederate.</p>\n";

      handoff($pingFederateHost, $pingFederateUser, $pingFederatePass, $resumePath, $entryUUID);

    }

?>
<!doctype html>

<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css"
        integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
    <link type="image/x-icon" href="https://www.pingidentity.com/etc.clientlibs/settings/wcm/designs/pic6/assets/resources/images/favicon-new.png" rel="shortcut icon">

    <title><?php echo $titleText; ?></title>
</head>

<body>

    <!-- <?php echo $entryUUID ?> -->
    <!-- <?php echo $response ?> -->

    <!-- navigation -->
    <nav class="navbar navbar-expand-lg navbar-light" style="background-color: #fff;">
        <a class="navbar-brand mb-1">
        <img src="https://www.pingidentity.com/content/dam/ping-6-2-assets/topnav-json-configs/Ping-Logo-Footer.svg" height="50" alt="">
        </a>
    </nav>
    <!-- /navigation -->

    <!-- user login -->
    <div class="container mt-5">
        <h2 class="display-4"><?php echo $titleText; ?></h2>
        <p><?php echo $dataText; ?></p>
        <p class="mb-5"><?php echo $purposeText; ?></p>
        <form method="POST">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="acceptConsent" name="acceptConsent" value="True" required />
                <label class="form-check-label" for="acceptConsent">I agree to the <?php echo $titleText; ?></label>
            </div>

            <input type="hidden" value="<?php echo $referenceId; ?>" name="REF" />
            <input type="hidden" value="<?php echo $resumePath; ?>" name="resumePath" />
            <input type="hidden" value="<?php echo $entryUUID; ?>" name="entryUUID" />
            <input type="hidden" value="<?php echo $version; ?>" name="version" />

            <a href="javascript:postOk();" class="btn btn-primary mt-5">
                Continue
            </a>

        </form>
    </div>
    <!-- /user login -->

    <!-- Optional JavaScript -->

    <!-- iovation -->
    <script language="javascript" src="../assets/scripts/iovation_adapter_custom.js"></script>
    <script language="javascript" src="../assets/scripts/iovation_device_profiling.js"></script>
    <!-- /iovation -->

    <!-- jQuery first, then Popper.js, then Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo"
        crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js"
        integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6"
        crossorigin="anonymous"></script>

    <script>
        function postOk() {
        
          if ($('#acceptConsent').is(":checked")) {
            document.forms[0].submit();
          }

        }

        function postOnReturn(e) {
            var keycode;
            if (window.event) keycode = window.event.keyCode;
            else if (e) keycode = e.which;
            else return true;

            if (keycode == 13) {
                postOk();
                return false;
            } else {
                return true;
            }
        }
    </script>

</body>

</html>
<?php  } ?>