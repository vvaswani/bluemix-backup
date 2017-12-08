<?php
// load classes
require '../vendor/autoload.php';

// load configuration
require '../config.php';

// if BlueMix VCAP_SERVICES environment available
// overwrite local credentials with BlueMix credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $config['settings']['db']['hostname'] = $services_json['cleardb'][0]['credentials']['hostname'];
  $config['settings']['db']['username'] = $services_json['cleardb'][0]['credentials']['username'];
  $config['settings']['db']['password'] = $services_json['cleardb'][0]['credentials']['password'];
  $config['settings']['db']['name'] = $services_json['cleardb'][0]['credentials']['name'];
}

try {

  // look for token in POST data
  // reject request if not found or mismatch
  $json = file_get_contents('php://input'); 
  $obj = json_decode($json);
  
  if (isset($obj->token) && ($obj->token == $config['settings']['token'])) {

    // initialize MySQL client
    // connect to database and run queries
    $mysqli = new mysqli(
      $config['settings']['db']['hostname'], 
      $config['settings']['db']['username'], 
      $config['settings']['db']['password'], 
      $config['settings']['db']['name']
    );

    if ($mysqli->connect_errno) {
      throw new Exception("Connection failed: " . $mysqli->connect_error);
    }

    $result = $mysqli->query("SELECT COUNT(id) FROM activity WHERE operation = 'CREATE' AND entity = 'FILE' AND date >= CURDATE() - INTERVAL 1 DAY"));
    $row = $result->fetch_row();
    $files = $row[0];
    unset($result);
    unset($row);

    $result = $mysqli->query("SELECT COUNT(id) FROM activity WHERE operation = 'CREATE' AND entity = 'PHOTO' AND date >= CURDATE() - INTERVAL 1 DAY"));
    $row = $result->fetch_row();
    $photos = $row[0];
    unset($result);
    unset($row);

    $result = $mysqli->query("SELECT COUNT(id) FROM activity WHERE (operation = 'CREATE' OR operation = 'UPDATE') AND entity = 'ACCOUNT' AND date >= CURDATE() - INTERVAL 1 DAY"));
    $row = $result->fetch_row();
    $accounts = $row[0];
    unset($result);
    unset($row);
    
    // initialize SendGrid client
    $sg = new \SendGrid($config['settings']['sendgrid']['key']);
    $from = new SendGrid\Email(null, "no-reply@example.com");
    $subject = "Activity summary";
    $to = new SendGrid\Email(null, $config['settings']['sendgrid']['to']);
    $content = new SendGrid\Content(
      "text/plain", 
      "Files created: " .  (int)$files . PHP_EOL .
      "Photos added: " .  (int)$photos . PHP_EOL .
      "Accounts modified: " .  (int)$accounts . PHP_EOL    
    );
    $mail = new SendGrid\Mail($from, $subject, $to, $content);
    $response = $sg->client->mail()->send()->post($mail);
    if ($response->statusCode() != 200 && $response->statusCode() != 202) {
      throw new \Exception('SendGrid failure, response code ' . $response->statusCode());    
    }    
    
    http_response_code(200);
    echo 'Summary sent successfully';

  } else {
    throw new Exception ('Invalid authentication token');
  }
} catch (\Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}

exit;

