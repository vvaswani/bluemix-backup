<?php
// load classes
require '../vendor/autoload.php';

// load configuration
require '../config.php';

use Ifsnop\Mysqldump as Mysqldump;
use Kunnu\Dropbox\DropboxApp;
use Kunnu\Dropbox\Dropbox;
use Kunnu\Dropbox\DropboxFile;

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
    // connect to database and dump contents to file
    $dump = new Mysqldump\Mysqldump(
      'mysql:host=' . $config['settings']['db']['hostname'] . ';dbname=' . $config['settings']['db']['name'],
      $config['settings']['db']['username'],
      $config['settings']['db']['password']
    );
    $filename = '../data/' . $config['settings']['db']['name'] . '-' . date('Y-m-d-h-i-s', time()) . '.sql';
    $dump->start($filename);

    // initialize Dropbox client
    $app = new DropboxApp(
      $config['settings']['dropbox']['app-key'], 
      $config['settings']['dropbox']['app-secret'],
      $config['settings']['dropbox']['app-token']
    );
    $dropbox = new Dropbox($app);
    $dropboxFile = new DropboxFile($filename);
    $uploadedFile = $dropbox->upload($dropboxFile, "/" . basename($filename));
    unlink($filename);
    
    // initialize SendGrid client
    $sg = new \SendGrid($config['settings']['sendgrid']['key']);
    $from = new SendGrid\Email(null, "no-reply@example.com");
    $subject = "Database backup notification";
    $to = new SendGrid\Email(null, $config['settings']['sendgrid']['to']);
    $content = new SendGrid\Content(
      "text/plain", 
      "Your database backup was successful. Your backup filename is: " .  basename($filename));
    $mail = new SendGrid\Mail($from, $subject, $to, $content);
    $response = $sg->client->mail()->send()->post($mail);
    if ($response->statusCode() != 200 && $response->statusCode() != 202) {
      throw new \Exception('SendGrid failure, response code ' . $response->statusCode());    
    }    
    
    http_response_code(200);
    echo 'Backup successful';

  } else {
    throw new Exception ('Invalid authentication token');
  }
} catch (\Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}

exit;

