#!/usr/bin/php
<?php

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = dirname(__FILE__) . '/';

// Test if batch mode
if (substr($sapi_type, 0, 3) == 'cgi') {
    echo "Error: You are using PHP for CGI. To execute " . $script_file . " from command line, you must use PHP for CLI mode.\n";
    exit;
}

// Global variables
$version = '1.7';
$error = 0;

// variables Locales
$id_produit_mensuel = 1; // On va facturer le produit suivant
$type_adherent = 2; // Type d'adhérents que l'on va facturer
$utilisateur_dolibarr = "admin"; // Utilisateur dolibarr qui envoie les factures
$message = "Bonjour,\n\nVoici la facture mensuelle ci jointe.\n\nPour toute question, réclamation, soucis : un petit message à contact@coworkinglille.com\n";
$subject = "Facture Mutualab";

// variables pour envoi de mail
$from = "contact@coworkinglille.com";
$replyto = "contact@coworkinglille.com";
$actiontypecode = 'AC_FAC';
                    


// -------------------- START OF YOUR CODE HERE --------------------
// Include Dolibarr environment
require_once($path . "master.inc.php");
// After this $db, $mysoc, $langs and $conf->entity are defined. Opened handler to database will be closed at end of file.

//$langs->setDefaultLang('fr_FR'); 	// To change default language of $langs
setlocale(LC_ALL, 'fr_FR');

$langs->load("main"); // To load language file for default language
@set_time_limit(0);

// Load user and its permissions
$result = $user->fetch('', $utilisateur_dolibarr); // Load user for login 'admin'. Comment line to run as anonymous user.
if (!$result > 0) {
    dol_print_error('', $user->error);
    exit;
}
$user->getrights();

print "***** " . $script_file . " (" . $version . ") *****\n";

// Start of transaction
$db->begin();

$commit = $argv[1];

// On recherche l'id adherent Bouclons tous
$sql = "SELECT email,rowid, fk_soc, prenom, nom ";
$sql .= " FROM " . MAIN_DB_PREFIX . "adherent as d ";
$sql .= " WHERE fk_adherent_type=$type_adherent ";


$outputFile = "#Pour ne pas prendre en compte une ligne du fichier, il suffit de la commenter avec #\n";
$outputFile .= "#seul l'identifiant à gauche du ; sera pris en compte\n";

$result = $db->query($sql);
if ($result) {
    $i = 0;
    while ($i < $db->num_rows($result)) {
        $i++;
        $objp = $db->fetch_object($result);
        if ($commit != "--commit")
        {
            print  "$i :" . $objp->firstname . " " . $objp->lastname . "(" . $objp->email . ") : adhérent(" . $objp->rowid . ") / Tiers : (" . $objp->fk_soc . ")  \n";
        }
        $outputFile .= $objp->fk_soc . ";" . $objp->email . ";$i :" . $objp->firstname . " " . $objp->lastname ." : adhérent(" . $objp->rowid . ") / Tiers : (" . $objp->fk_soc . ")  \n";
    }
}

if (!isset($argv[1])) { // Check parameters
    print "Usage: " . $script_file . " effectue la verification  ...\n";
    print "Usage: " . $script_file . " --commit  pour executer et lire le fichier liste.txt (qui peut permet de commenter avant) ...\n";
    $file = fopen("liste.txt", "w");
    fwrite($file, $outputFile);
    fclose($file);
    exit;
}

if ($commit != "--commit") {
    $file = fopen("liste.txt", "w");
    fwrite($file, $outputFile);
    fclose($file);
    print "Usage: " . $script_file . " effectue la verification  ...\n";
    print "Usage: " . $script_file . " --commit  pour executer et lire le fichier liste.txt (qui peut permet de commenter avant) ...\n";
    exit;

}


require_once(DOL_DOCUMENT_ROOT . "/compta/facture/class/facture.class.php");

print  "\n\n";

$lines = file("liste.txt");
/*On parcourt le tableau $lines et on affiche le contenu de chaque ligne précédée de son numéro*/
foreach ($lines as $lineNumber => $lineContent) {
    print "   $lineContent \n";
    if ($lineContent[0] == "#") {
    } else {
        $ids = explode(";", $lineContent);
        $socid = $ids[0];
        $email= $ids[1];


// Create invoice object
        $facture = new Facture($db);



        $societe = new Societe ($db);
        $societe->fetch($socid);
        // CAI : modif a valider
		$pourcent_remise = $societe->remise_client / 100 ;
		/*if ( $societe->remise_client == 0 )
               $pourcent_remise=0;
        else
            $pourcent_remise = $societe->remise_client / 100 ;*/
			
        $facture->socid = $socid; // Put id of third party (rowid in llx_societe table)

        $facture->date = mktime();
        $facture->cond_reglement_id = 1;

        $product = new Product ($db);
        $product->fetch($id_produit_mensuel);
        $prix = $product->price;
		$prix_ttc = $product->price_ttc;
		$tva = $product->tva_tx;
		$label = $product->label;
		$description = $product->description;

        // calcul des varaibles tot_xxx
		$tot_ht = $prix - ($prix * $pourcent_remise );
		$tot_tva = $tot_ht * $tva/100;
		$tot_ttc = $tot_ht + $tot_tva;
		
		$line1 = new FactureLigne($db);
        $line1->desc = $description;
		$line1->qty = 1;
        $line1->tva_tx = $tva;
        $line1->subprice = round($prix,2);
		
        $line1->total_tva = round($tot_tva,2);
        $line1->total_ht = round($tot_ht,2);
        $line1->total_ttc = round($tot_ttc,2);
        $line1->remise_percent = $societe->remise_client;
        
		$line1->fk_product = $id_produit_mensuel;

        $facture->lines[] = $line1;


// Create invoice
        $idobject = $facture->create($user);
        if ($idobject > 0) {
// Change status to validated
            $result = $facture->validate($user);
            if ($result > 0) {
                print "   Facture N°" . $idobject . " créée\n";
				//$hidedetails = 0;//(GETPOST('hidedetails', 'int') ? GETPOST('hidedetails', 'int') : (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS) ? 1 : 0));
                //$hidedesc = 0;//(GETPOST('hidedesc', 'int') ? GETPOST('hidedesc', 'int') : (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC) ? 1 : 0));
                //$hideref = 0;//(GETPOST('hideref', 'int') ? GETPOST('hideref', 'int') : (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF) ? 1 : 0));
                /*print "hidedetails: " . GETPOST('hidedetails') . "\n";
				print "hidedesc   : " . GETPOST('hidedesc') . "\n";
				print "hideref    : " . GETPOST('hideref') . "\n";*/
				//facture_pdf_create($db, $facture, $facture->modelpdf, $langs, $hidedetails, $hidedesc, $hideref);
				facture_pdf_create($db, $facture, $facture->modelpdf, $langs);
				
                $file = $conf->facture->dir_output . '/' . $facture->ref . '/' . $facture->ref . '.pdf';


                /*
                 * Send mail
                 */
                $langs->load('mails');

                $actiontypecode = '';
                $actionmsg = '';
                $actionmsg2 = '';

                $sendto = $email;
                $sendtoid = 0;
                //            $sendto = $object->client->email;
                //          $sendto = $object->client->contact_get_property($_POST['receiver'], 'email');
                if (dol_strlen($sendto)) {
                    $langs->load("commercial");

                    $actionmsg = $langs->transnoentities('MailSentBy') . ' ' . $from . ' ' . $langs->transnoentities('To') . ' ' . $sendto . ".\n";
                    if ($message) {
                        $actionmsg .= $langs->transnoentities('MailTopic') . ": " . $subject . "\n";
                        $actionmsg .= $langs->transnoentities('TextUsedInTheMessageBody') . ":\n";
                        $actionmsg .= $message;
                    }

                    // Create form object
                    include_once DOL_DOCUMENT_ROOT . '/core/class/html.formmail.class.php';
                    $formmail = new FormMail($db);
                    $formmail->clear_attached_files();
                    $formmail->add_attached_files($file, basename($file), dol_mimetype($file));

                    $attachedfiles = $formmail->get_attached_files();
                    $filepath = $attachedfiles['paths'];
                    $filename = $attachedfiles['names'];
                    $mimetype = $attachedfiles['mimes'];

                    // Send mail
                    require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
                    $mailfile = new CMailFile($subject, $sendto, $from, $message, $filepath, $mimetype, $filename, $sendtocc, '', $deliveryreceipt, -1);
                    if ($mailfile->error) {
                        $mesgs[] = '<div class="error">' . $mailfile->error . '</div>';
                    } else {
                        $result = $mailfile->sendfile();
                        if ($result) {
                            $error = 0;

                            // Initialisation donnees
                            $object->sendtoid = $sendtoid;
                            $object->actiontypecode = $actiontypecode;
                            $object->actionmsg = $actionmsg; // Long text
                            $object->actionmsg2 = $actionmsg2; // Short text
                            $object->fk_element = $object->id;
                            $object->elementtype = $object->element;

                            // Appel des triggers
                            include_once DOL_DOCUMENT_ROOT . '/core/class/interfaces.class.php';
                            $interface = new Interfaces($db);
                            $result = $interface->run_triggers('BILL_SENTBYMAIL', $object, $user, $langs, $conf);
                            if ($result < 0) {
                                $error++;
                                $this->errors = $interface->errors;
                            }
                            // Fin appel triggers

                            if ($error) {
                                dol_print_error($db);
                            } else {
                                // Redirect here
                                // This avoid sending mail twice if going out and then back to page
                                //$mesg = $langs->trans('MailSuccessfulySent', $mailfile->getValidAddress($from, 2), $mailfile->getValidAddress($sendto, 2));
                                //setEventMessage($mesg);
                                //header('Location: ' . $_SERVER["PHP_SELF"] . '?facid=' . $object->id);
                                //exit;
                            }
                        } else {
                            $langs->load("other");
                            $mesg = '<div class="error">';
                            if ($mailfile->error) {
                                $mesg .= $langs->trans('ErrorFailedToSendMail', $from, $sendto);
                                $mesg .= '<br>' . $mailfile->error;
                            } else {
                                $mesg .= 'No mail sent. Feature is disabled by option MAIN_DISABLE_ALL_MAILS';
                            }
                            $mesg .= '</div>';
                            $mesgs[] = $mesg;
                        }
                    }
                }

            } else {
                $error++;
                dol_print_error($db, $facture->error);
            }
        } else {
            $error++;
            dol_print_error($db, $facture->error);
        }
    }

}


// -------------------- END OF YOUR CODE --------------------

if (!$error) {
    $db->commit();
    print '--- end ok' . "\n";
} else {
    print '--- end error code=' . $error . "\n";
    $db->rollback();
}

$db->close();

return $error;
?>
