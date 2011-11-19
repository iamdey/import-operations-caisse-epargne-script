<?php

/**
 * Script d'import des opérations d'un compte de la caisse d'épargne
 * pour Grisbi
 * 
 * @author esion
 * @version 1 - 12/11/11
 */

/**
 * Configuration
 */
if(!file_exists('settings.php'))
{
    $usage = <<<USAGE
Le fichier settings.php est manquant.

Instructions:
Renommer le fichier example.settings.php en settings.php puis l'editer,
Indiquer l'identifiant de compte, le mot de passe à utiliser
et pour chaque compte à synchroniser ajouter une ligne avec le numéro IBAN partiel.

Lancer le script en ligne de commande avec 'php app.php' tous les 2 mois minimum.
USAGE;
    echo $usage;die;
}

include('settings.php');


$bank_login_url 	= 'https://www.net382.caisse-epargne.fr/login.aspx';
$bank_config_url 	= 'https://www.net382.caisse-epargne.fr/Portail.aspx';
$bank_download_url 	= 'https://www.net382.caisse-epargne.fr/Pages/telechargement.aspx';
$days_intervale 	= 60;
$filename           = 'temp.qif';
$cookie             = '.cookie';

/**
 * Début du script
 */
echo "\n\n";
echo '[start] import script at ' . date('H:i:s') . "\n";

//TODO: limiter la boucle à la config du fichier et le dl
//FIXME: il semble ne pas prendre d'autres numéro de compte. 
//il faudrait envoyer un ajax en spécifiant le nouveau compte avant de configurer et de soumettre
foreach($a_bank_iban as $a_iban)
{
    //définir le compte
    $bank_iban_partial 	= $a_iban['code'] . $a_iban['agency'] . $a_iban['account'] . $a_iban['key'];
    $last_import_file 	= '.last_import.' . $bank_iban_partial;

    echo "define dates\n\n";
    //définir la date de début et de fin
    $date_import_start = new DateTime();
    $date_import_start->modify('-' . $days_intervale . ' days');

    if(file_exists($last_import_file))
    {
        $str_date = file_get_contents($last_import_file);
        
        if($timestamp = strtotime($str_date))
        {
            $date_import_start = new DateTime($str_date);
        }
    }

    //end date is yesterday
    $date_import_end = new DateTime();
    $date_import_end->modify('-1 day');

    $intervale = $date_import_start->diff($date_import_end);

    if($intervale->format('%d') < 1)
    {
        echo 'Rien à importer pour le compte ' . $a_iban['account'] . "\n\n";
        continue; //compte suivant
    }


    $filename = sprintf('%s_%s_%s.qif', $date_import_start->format('y-m-d'), $date_import_end->format('y-m-d'), $bank_iban_partial);



    //se connecter à caisse épargne

    echo "login\n\n";
    $ch = curl_init();

    $post_params = array(
        'codconf' 	=> $account_passwork,
        'nuabbd' 	=> $account_id,
        'ctx'		=> '',
        'ctx_routage'	=> '',
    );

    $a_opts = array(
        CURLOPT_URL 	=>  $bank_login_url,
        CURLOPT_POST 	=> true,
        CURLOPT_POSTFIELDS => $post_params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
    );

    curl_setopt_array($ch, $a_opts);

    $res = curl_exec($ch);
    $info = curl_getinfo($ch);

    if(!$res || strpos($res, '<h2>Object moved to <a href="/Portail.aspx">here</a>.</h2>') === false)
    {
        die('login failed');
    }




    //Récupération de la variable de vérification ajax
    echo "recuperation du faux hash pour param ajax\n\n";
    $a_opts = array(
        CURLOPT_URL 	=>  $bank_config_url,
        CURLOPT_HTTPGET 	=> true,
        CURLOPT_RETURNTRANSFER => true,
    );

    curl_setopt_array($ch, $a_opts);

    $html = curl_exec($ch);
    $info = curl_getinfo($ch);

    $ret = preg_match_all('#<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="(.*)" />|<input type="hidden" name="__EVENTVALIDATION" id="__EVENTVALIDATION" value="(.*)" />#', $html, $a_data, PREG_SET_ORDER);

    if(count($a_data) != 2)
    {
        die('erreur recup ajax param : ça doit être un bug ou un changement dans l\'interface de la caisse d\'epargne');
    }

    if(!function_exists('extract_my_ajax_param'))
    {
        function extract_my_ajax_param($field)
        {
            foreach($field as $var)
            {
                if(strpos($var, '/') === 0)
                {
                    return $var;
                }
            }
        }
    }

    $ajax_viewstate = extract_my_ajax_param($a_data[0]);
    $ajax_eventvalidation = extract_my_ajax_param($a_data[1]);




    //recuperation de la validation ajax

    echo "recuperation du vrai hash pour param ajax\n\n";

    $post_params = array(
        'MM$m_CH$IsMsgInit' => 'O',
        '__EVENTARGUMENT' => 'CPTDMTE0',
        '__EVENTTARGET' => 'Menu_AJAX',
        '__EVENTVALIDATION' => $ajax_eventvalidation,
        '__VIEWSTATE' => $ajax_viewstate,
        'm_ScriptManager' => 'm_ScriptManager|Menu_AJAX',
    );


    $a_opts = array(
        CURLOPT_URL 	=>  $bank_config_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST 	=> true,
        CURLOPT_POSTFIELDS => $post_params,
        CURLOPT_HTTPHEADER => array('Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'X-MicrosoftAjax:Delta=true'),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:7.0.1) Gecko/20100101 Firefox/7.0.1',
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_REFERER => 'https://www.net382.caisse-epargne.fr/Portail.aspx',
    );
    curl_setopt_array($ch, $a_opts);

    $res = curl_exec($ch);
    $info = curl_getinfo($ch);

    preg_match('#__VIEWSTATE\|(.*?)\|#', $res, $matchs);
    $ajax_viewstate = $matchs[1];
    preg_match('#__EVENTVALIDATION\|(.*?)\|#', $res, $matchs);
    $ajax_eventvalidation = $matchs[1];
    //end



    //spécifier les dates de début et fin des opérations à dl - POST

    echo "spécification des paramètres du fichier\n\n";

    $post_params = array(
        'MM$m_CH$IsMsgInit' => 'O',
        'MM$TELECHARGE_OPERATIONS$m_ExDDLListeComptes' 	=> sprintf('C#%s#%s#EUR', $bank_iban_partial, date('Ymd')),
        'MM$TELECHARGE_OPERATIONS$m_DateDebut$JJ' 	=> $date_import_start->format('d'),
        'MM$TELECHARGE_OPERATIONS$m_DateDebut$MM' 	=> $date_import_start->format('m'),
        'MM$TELECHARGE_OPERATIONS$m_DateDebut$AA' 	=> $date_import_start->format('y'),
        'MM$TELECHARGE_OPERATIONS$m_DateFin$JJ' 	=> $date_import_end->format('d'),
        'MM$TELECHARGE_OPERATIONS$m_DateFin$MM' 	=> $date_import_end->format('m'),
        'MM$TELECHARGE_OPERATIONS$m_DateFin$AA' 	=> $date_import_end->format('y'),
        'MM$TELECHARGE_OPERATIONS$ddlChoixLogiciel' 	=> 'Quicken dates françaises (fichier .qif)',
        '__EVENTARGUMENT' => '',
        '__EVENTTARGET' => 'MM$TELECHARGE_OPERATIONS$m_ChoiceBar$lnkRight',
        '__EVENTVALIDATION' => $ajax_eventvalidation,
        '__LASTFOCUS' => '',
        '__VIEWSTATE' => $ajax_viewstate,
        'm_ScriptManager' => 'MM$m_UpdatePanel|MM$TELECHARGE_OPERATIONS$m_ChoiceBar$lnkRight',
    );


    $a_opts = array(
        CURLOPT_URL 	=>  $bank_config_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST 	=> true,
        CURLOPT_POSTFIELDS => $post_params,
        CURLOPT_HTTPHEADER => array('Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'X-MicrosoftAjax:Delta=true'),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:7.0.1) Gecko/20100101 Firefox/7.0.1',
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_REFERER => 'https://www.net382.caisse-epargne.fr/Portail.aspx',
    );
    curl_setopt_array($ch, $a_opts);

    $res = curl_exec($ch);
    $info = curl_getinfo($ch);

    $str_post_params = '';
    foreach($post_params as $k => $value)
    {
        $str_post_params .= $k . '=' . $value . "\n";
    }



    echo "téléchargement du fichier\n\n";

    //télécharger le fichier - GET

    $a_opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_URL => $bank_download_url,
        CURLOPT_HTTPHEADER => array('Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:7.0.1) Gecko/20100101 Firefox/7.0.1',
        CURLOPT_COOKIEJAR => $cookie,
    );
    curl_setopt_array($ch, $a_opts);

    $file = curl_exec($ch);
    $info = curl_getinfo($ch);

    curl_close($ch);

    //stocker le fichier

    echo "sauvegarde du fichier\n\n";

    if(!$file)
    {
        die('Ow noes no file');
    }

    file_put_contents($filename, $file);


    //définir la date du dernier import
    echo "maj de la date dernier import\n\n";
    file_put_contents($last_import_file, $date_import_end->format('Y/m/d'));

}

//importer dans grisbi (ou pas) : TODO

echo "\n\n";
echo '[end] import script at ' . date('H:i:s') . "\n\n";
