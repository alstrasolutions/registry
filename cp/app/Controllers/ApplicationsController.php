<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class ApplicationsController extends Controller
{

    public function listApplications(Request $request, Response $response)
    {
        return view($response,'admin/domains/listApplications.twig');
    }
    
    public function createApplication(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            $registrar_id = $data['registrar'] ?? null;
            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            if ($_SESSION["auth_roles"] != 0) {
                $registrar = true;
            } else {
                $registrar = null;
            }
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }
      
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;
            
            $phaseType = $data['phaseType'] ?? null;
            $phaseName = $data['phaseName'] ?? null;
            $smd = $data['smd'] ?? null;
            
            $nameservers = !empty($data['nameserver']) ? $data['nameserver'] : null;
            $nameserver_ipv4 = !empty($data['nameserver_ipv4']) ? $data['nameserver_ipv4'] : null;
            $nameserver_ipv6 = !empty($data['nameserver_ipv6']) ? $data['nameserver_ipv6'] : null;
      
            $authInfo = $data['authInfo'] ?? null;
            
            $parts = extractDomainAndTLD($domainName);
            $label = $parts['domain'];
            $domain_extension = $parts['tld'];
            $invalid_domain = validate_label($domainName, $db);

            if ($invalid_domain) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Invalid domain name in application',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $valid_tld = false;
            $result = $db->select('SELECT id, tld FROM domain_tld');

            foreach ($result as $row) {
                if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                    $valid_tld = true;
                    $tld_id = $row['id'];
                    break;
                }
            }

            if (!$valid_tld) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Invalid domain extension in application',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            $domain_already_exist = $db->selectValue(
                'SELECT id FROM application WHERE name = ? and clid = ? LIMIT 1',
                [$domainName, $clid]
            );

            if ($domain_already_exist) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Application already exists',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            $domain_already_reserved = $db->selectValue(
                'SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1',
                [$label]
            );

            if ($domain_already_reserved) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Domain name in application is reserved or restricted',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
       
            $date_add = 12;

            $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);

            $registrar_balance = $result['accountBalance'];
            $creditLimit = $result['creditLimit'];
            
            $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'create');
            $price = $returnValue['price'];

            if (!$price) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'The price, period and currency for such TLD are not declared',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Low credit: minimum threshold reached',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $nameservers = array_filter($data['nameserver'] ?? [], function($value) {
                return !empty($value) && $value !== null;
            });
            $nameserver_ipv4 = array_filter($data['nameserver_ipv4'] ?? [], function($value) {
                return !empty($value) && $value !== null;
            });
            $nameserver_ipv6 = array_filter($data['nameserver_ipv6'] ?? [], function($value) {
                return !empty($value) && $value !== null;
            });
            
            if (!empty($nameservers)) {
                if (count($nameservers) !== count(array_unique($nameservers))) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'Duplicate nameservers detected. Please provide unique nameservers.',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
                
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        return view($response, 'admin/domains/createApplication.twig', [
                            'domainName' => $domainName,
                            'error' => 'Invalid hostName',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        return view($response, 'admin/domains/createApplication.twig', [
                            'domainName' => $domainName,
                            'error' => 'Invalid hostName',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                }
            }
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'Registrant does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'Admin contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'Tech contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'Billing contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createApplication.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if (!$authInfo) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Missing application authinfo',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Password needs to be at least 6 and up to 16 characters long',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (!preg_match('/[A-Z]/', $authInfo)) {
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Password should have both upper and lower case characters',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $registrant_id = $db->selectValue(
                'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                [$contactRegistrant]
            );

            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->insert('application', [
                    'name' => $domainName,
                    'tldid' => $tld_id,
                    'registrant' => $registrant_id,
                    'crdate' => $crdate,
                    'exdate' => null,
                    'lastupdate' => null,
                    'clid' => $clid,
                    'crid' => $clid,
                    'upid' => null,
                    'trdate' => null,
                    'trstatus' => null,
                    'reid' => null,
                    'redate' => null,
                    'acid' => null,
                    'acdate' => null,
                    'rgpstatus' => null,
                    'addPeriod' => null,
                    'authtype' => 'pw',
                    'authinfo' => $authInfo,
                    'phase_name' => $phaseName,
                    'phase_type' => $phaseType,
                    'smd' => $smd
                ]);
                $domain_id = $db->getlastInsertId();
                
                $db->insert('application_status', [
                    'domain_id' => $domain_id,
                    'status' => 'pendingValidation'
                ]);

                $db->exec(
                    'UPDATE registrar SET accountBalance = accountBalance - ? WHERE id = ?',
                    [$price, $clid]
                );

                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                    [$clid, "create application for $domainName for period $date_add MONTH", "-$price"]
                );

                $row = $db->selectRow(
                    'SELECT crdate FROM application WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $from = $row['crdate'];

                $currentDateTime = new \DateTime();
                $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'statement',
                    [
                        'registrar_id' => $clid,
                        'date' => $stdate,
                        'command' => 'create',
                        'domain_name' => $domainName,
                        'length_in_months' => $date_add,
                        'fromS' => $from,
                        'toS' => $from,
                        'amount' => $price
                    ]
                );

                if (!empty($nameservers)) {
                    foreach ($nameservers as $index => $nameserver) {
                        
                        $internal_host = false;
                        
                        $result = $db->select('SELECT tld FROM domain_tld');

                        foreach ($result as $row) {
                            if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                                $internal_host = true;
                                break;
                            }
                        }

                        $hostName_already_exist = $db->selectValue(
                            'SELECT id FROM host WHERE name = ? LIMIT 1',
                            [$nameserver]
                        );

                        if ($hostName_already_exist) {
                            $domain_host_map_id = $db->selectValue(
                                'SELECT domain_id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );

                            if (!$domain_host_map_id) {
                                $db->insert(
                                    'application_host_map',
                                    [
                                        'domain_id' => $domain_id,
                                        'host_id' => $hostName_already_exist
                                    ]
                                );
                            } else {
                                $currentDateTime = new \DateTime();
                                $logdate = $currentDateTime->format('Y-m-d H:i:s.v');
                                $db->insert(
                                    'error_log',
                                    [
                                        'registrar_id' => $clid,
                                        'log' => "Domain : $domainName ; hostName : $nameserver - is duplicated",
                                        'date' => $logdate
                                    ]
                                );
                            }
                        } else {
                            $currentDateTime = new \DateTime();
                            $host_date = $currentDateTime->format('Y-m-d H:i:s.v');
                            
                            if ($internal_host) {
                                $db->insert(
                                    'host',
                                    [
                                        'name' => $nameserver,
                                        'domain_id' => $domain_id,
                                        'clid' => $clid,
                                        'crid' => $clid,
                                        'crdate' => $host_date
                                    ]
                                );
                                $host_id = $db->getlastInsertId();
                            } else {
                                $db->insert(
                                    'host',
                                    [
                                        'name' => $nameserver,
                                        'clid' => $clid,
                                        'crid' => $clid,
                                        'crdate' => $host_date
                                    ]
                                );
                                $host_id = $db->getlastInsertId();
                            }

                            $db->insert(
                                'application_host_map',
                                [
                                    'domain_id' => $domain_id,
                                    'host_id' => $host_id
                                ]
                            );
                            
                            $db->insert(
                                'host_status',
                                [
                                    'status' => 'ok',
                                    'host_id' => $host_id
                                ]
                            );
                            
                            if ($internal_host) {
                                if (empty($nameserver_ipv4[$index]) && empty($nameserver_ipv6[$index])) {
                                    return view($response, 'admin/domains/createApplication.twig', [
                                        'domainName' => $domainName,
                                        'error' => 'Error: No IPv4 or IPv6 addresses provided for internal host',
                                        'registrars' => $registrars,
                                        'registrar' => $registrar,
                                    ]);
                                }
    
                                if (isset($nameserver_ipv4[$index]) && !empty($nameserver_ipv4[$index])) {
                                    $ipv4 = normalize_v4_address($nameserver_ipv4[$index]);
                                    
                                    $db->insert(
                                        'host_addr',
                                        [
                                            'host_id' => $host_id,
                                            'addr' => $ipv4,
                                            'ip' => 'v4'
                                        ]
                                    );
                                }

                                if (isset($nameserver_ipv6[$index]) && !empty($nameserver_ipv6[$index])) {
                                    $ipv6 = normalize_v6_address($nameserver_ipv6[$index]);
                                    
                                    $db->insert(
                                        'host_addr',
                                        [
                                            'host_id' => $host_id,
                                            'addr' => $ipv6,
                                            'ip' => 'v6'
                                        ]
                                    );
                                }
                            }
                            
                        }
                    }
                }
                
                $contacts = [
                    'admin' => $data['contactAdmin'] ?? null,
                    'tech' => $data['contactTech'] ?? null,
                    'billing' => $data['contactBilling'] ?? null
                ];

                foreach ($contacts as $type => $contact) {
                    if ($contact !== null) {
                        $contact_id = $db->selectValue(
                            'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                            [$contact]
                        );

                        // Check if $contact_id is not null before insertion
                        if ($contact_id !== null) {
                            $db->insert(
                                'application_contact_map',
                                [
                                    'domain_id' => $domain_id,
                                    'contact_id' => $contact_id,
                                    'type' => $type
                                ]
                            );
                        }
                    }
                }

                $result = $db->selectRow(
                    'SELECT crdate,exdate FROM domain WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $crdate = $result['crdate'];
                $exdate = $result['exdate'];

                $curdate_id = $db->selectValue(
                    'SELECT id FROM statistics WHERE date = CURDATE()'
                );

                if (!$curdate_id) {
                    $db->exec(
                        'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                    );
                }

                $db->exec(
                    'UPDATE statistics SET created_domains = created_domains + 1 WHERE date = CURDATE()'
                );
                
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Database failure: ' . $e->getMessage(),
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                return view($response, 'admin/domains/createApplication.twig', [
                    'domainName' => $domainName,
                    'error' => 'Database failure: ' . $e->getMessage(),
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $crdate = $db->selectValue(
                "SELECT crdate FROM application WHERE id = ? LIMIT 1",
                [$domain_id]
            );
            
            $this->container->get('flash')->addMessage('success', 'Application ' . $domainName . ' has been created successfully on ' . $crdate);
            return $response->withHeader('Location', '/applications')->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/createApplication.twig', [
            'registrars' => $registrars,
            'registrar' => $registrar,
        ]);
    }
    
    public function viewApplication(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }
        
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, clid, idnlang, authinfo, authtype, phase_name, phase_type, smd FROM application WHERE name = ?',
            [ $args ]);

            if ($domain) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$domain['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the applications view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/applications')->withStatus(302);
                    }
                }
                
                $domainRegistrant = $db->selectRow('SELECT identifier FROM contact WHERE id = ?',
                [ $domain['registrant'] ]);
                $domainStatus = $db->select('SELECT status FROM application_status WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainHostsQuery = '
                    SELECT dhm.id, dhm.domain_id, dhm.host_id, h.name
                    FROM application_host_map dhm
                    JOIN host h ON dhm.host_id = h.id
                    WHERE dhm.domain_id = ?';

                $domainHosts = $db->select($domainHostsQuery, [$domain['id']]);
                $domainContactsQuery = '
                    SELECT dcm.id, dcm.domain_id, dcm.contact_id, dcm.type, c.identifier 
                    FROM application_contact_map dcm
                    JOIN contact c ON dcm.contact_id = c.id
                    WHERE dcm.domain_id = ?';
                $domainContacts = $db->select($domainContactsQuery, [$domain['id']]);

                return view($response,'admin/domains/viewApplication.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainRegistrant' => $domainRegistrant,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrars' => $registrars,
                    'currentUri' => $uri
                ]);
            } else {
                // Domain does not exist, redirect to the applications view
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }

        } else {
            // Redirect to the applications view
            return $response->withHeader('Location', '/applications')->withStatus(302);
        }

    }
    
    public function updateApplication(Request $request, Response $response, $args)
    {
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }
        
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
            [ $args ]);

            if ($domain) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$domain['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the domains view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }
                
                $domainRegistrant = $db->selectRow('SELECT identifier FROM contact WHERE id = ?',
                [ $domain['registrant'] ]);
                $domainStatus = $db->select('SELECT status FROM application_status WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainAuth = $db->selectRow('SELECT authinfo FROM domain_authInfo WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainSecdns = $db->select('SELECT * FROM secdns WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainHostsQuery = '
                    SELECT dhm.id, dhm.domain_id, dhm.host_id, h.name
                    FROM application_host_map dhm
                    JOIN host h ON dhm.host_id = h.id
                    WHERE dhm.domain_id = ?';

                $domainHosts = $db->select($domainHostsQuery, [$domain['id']]);
                $domainContactsQuery = '
                    SELECT dcm.id, dcm.domain_id, dcm.contact_id, dcm.type, c.identifier 
                    FROM application_contact_map dcm
                    JOIN contact c ON dcm.contact_id = c.id
                    WHERE dcm.domain_id = ?';
                $domainContacts = $db->select($domainContactsQuery, [$domain['id']]);
                
                $csrfTokenName = $this->container->get('csrf')->getTokenName();
                $csrfTokenValue = $this->container->get('csrf')->getTokenValue();


                return view($response,'admin/domains/updateDomain.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainAuth' => $domainAuth,
                    'domainRegistrant' => $domainRegistrant,
                    'domainSecdns' => $domainSecdns,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrar' => $registrars,
                    'currentUri' => $uri,
                    'csrfTokenName' => $csrfTokenName,
                    'csrfTokenValue' => $csrfTokenValue
               ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }
    
    public function updateApplicationProcess(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }
            
            $domain_id = $db->selectValue(
                'SELECT id FROM domain WHERE name = ?',
                [$domainName]
            );
            $results = $db->select(
                'SELECT status FROM application_status WHERE domain_id = ?',
                [ $domain_id ]
            );

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pendingTransfer/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow renew, first change the status');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;
            
            $nameservers = $data['nameserver'] ?? [];

            $dsKeyTag = isset($data['dsKeyTag']) ? (int)$data['dsKeyTag'] : null;
            $dsAlg = $data['dsAlg'] ?? null;
            $dsDigestType = isset($data['dsDigestType']) ? (int)$data['dsDigestType'] : null;
            $dsDigest = $data['dsDigest'] ?? null;
            
            $dnskeyFlags = $data['dnskeyFlags'] ?? null;
            $dnskeyProtocol = $data['dnskeyProtocol'] ?? null;
            $dnskeyAlg = $data['dnskeyAlg'] ?? null;
            $dnskeyPubKey = $data['dnskeyPubKey'] ?? null;
            
            $authInfo = $data['authInfo'] ?? null;
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Registrant does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide registrant identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Admin contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide admin contact identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Tech contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide tech contact identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Billing contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide billing contact identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if (!$authInfo) {
                $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' can not be updated: Missing domain authinfo');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }

            if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                $this->container->get('flash')->addMessage('error', 'Password needs to be at least 6 and up to 16 characters long');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }

            if (!preg_match('/[A-Z]/', $authInfo)) {
                $this->container->get('flash')->addMessage('error', 'Password should have both upper and lower case characters');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            $registrant_id = $db->selectValue(
                'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                [$contactRegistrant]
            );
            
            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->update('domain', [
                    'registrant' => $registrant_id,
                    'lastupdate' => $update,
                    'upid' => $clid
                ],
                [
                    'name' => $domainName
                ]
                );
                $domain_id = $db->selectValue(
                    'SELECT id FROM domain WHERE name = ?',
                    [$domainName]
                );

                $db->update(
                    'domain_authInfo',
                    [
                        'authinfo' => $authInfo
                    ],
                    [
                        'id' => $domain_id,
                        'authtype' => 'pw'
                    ]
                );
                
                // Data sanity checks
                // Validate keyTag
                if (!empty($dsKeyTag)) {
                    if (!is_int($dsKeyTag)) {
                        $this->container->get('flash')->addMessage('error', 'Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                
                    if ($dsKeyTag < 0 || $dsKeyTag > 65535) {
                        $this->container->get('flash')->addMessage('error', 'Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                }

                // Validate alg
                $validAlgorithms = [2, 3, 5, 6, 7, 8, 10, 13, 14, 15, 16];
                if (!empty($dsAlg) && !in_array($dsAlg, $validAlgorithms)) {
                    $this->container->get('flash')->addMessage('error', 'Incomplete algorithm provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate digestType and digest
                if (!empty($dsDigestType) && !is_int($dsDigestType)) {
                    $this->container->get('flash')->addMessage('error', 'Incomplete digest type provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
                $validDigests = [
                    1 => 40,  // SHA-1
                    2 => 64,  // SHA-256
                    4 => 96   // SHA-384
                ];
                if (empty($validDigests[$dsDigestType])) {
                    $this->container->get('flash')->addMessage('error', 'Unsupported digest type');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
                if (!empty($dsDigest)) {
                    if (strlen($dsDigest) != $validDigests[$dsDigestType] || !ctype_xdigit($dsDigest)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid digest length or format');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                }

                // Data sanity checks for keyData
                // Validate flags
                $validFlags = [256, 257];
                if (!empty($dnskeyFlags) && !in_array($dnskeyFlags, $validFlags)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid flags provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate protocol
                if (!empty($dnskeyProtocol) && $dnskeyProtocol != 3) {
                    $this->container->get('flash')->addMessage('error', 'Invalid protocol provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate algKeyData
                if (!empty($dnskeyAlg)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid algorithm encoding');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate pubKey
                if (!empty($dnskeyPubKey) && base64_encode(base64_decode($dnskeyPubKey, true)) !== $dnskeyPubKey) {
                    $this->container->get('flash')->addMessage('error', 'Invalid public key encoding');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if (!empty($dsKeyTag)) {
                    // Base data for the insert
                    $insertData = [
                        'domain_id' => $domain_id,
                        'maxsiglife' => $maxSigLife,
                        'interface' => 'dsData',
                        'keytag' => $dsKeyTag,
                        'alg' => $dsAlg,
                        'digesttype' => $dsDigestType,
                        'digest' => $dsDigest,
                        'flags' => null,
                        'protocol' => null,
                        'keydata_alg' => null,
                        'pubkey' => null
                    ];

                    // Check additional conditions for dnskeyFlags
                    if (isset($dnskeyFlags) && $dnskeyFlags !== "") {
                        $insertData['flags'] = $dnskeyFlags;
                        $insertData['protocol'] = $dnskeyProtocol;
                        $insertData['keydata_alg'] = $dnskeyAlg;
                        $insertData['pubkey'] = $dnskeyPubKey;
                    }

                    // Perform the insert
                    $db->insert('secdns', $insertData);
                }
   
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                
                    $hostName_already_exist = $db->selectValue(
                        'SELECT id FROM host WHERE name = ? LIMIT 1',
                        [$nameserver]
                    );

                    if ($hostName_already_exist) {
                        $domain_host_map_id = $db->selectValue(
                            'SELECT domain_id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                            [$domain_id, $hostName_already_exist]
                        );

                        if (!$domain_host_map_id) {
                            $db->insert(
                                'application_host_map',
                                [
                                    'domain_id' => $domain_id,
                                    'host_id' => $hostName_already_exist
                                ]
                            );
                        } else {
                            $host_map_id = $db->selectValue(
                                'SELECT id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );
                            
                            $db->update(
                                'application_host_map',
                                [
                                    'host_id' => $hostName_already_exist
                                ],
                                [
                                    'domain_id' => $domain_id,
                                    'id' => $host_map_id
                                ]
                            );
                        }
                    } else {
                        $currentDateTime = new \DateTime();
                        $host_date = $currentDateTime->format('Y-m-d H:i:s.v');
                        $db->insert(
                            'host',
                            [
                                'name' => $nameserver,
                                'domain_id' => $domain_id,
                                'clid' => $clid,
                                'crid' => $clid,
                                'crdate' => $host_date
                            ]
                        );
                        $host_id = $db->getlastInsertId();

                        $db->insert(
                            'application_host_map',
                            [
                                'domain_id' => $domain_id,
                                'host_id' => $host_id
                            ]
                        );
                        
                        $db->insert(
                            'host_status',
                            [
                                'status' => 'ok',
                                'host_id' => $host_id
                            ]
                        );
                        
                        if (isset($nameserver_ipv4[$index]) && !empty($nameserver_ipv4[$index])) {
                            $ipv4 = normalize_v4_address($nameserver_ipv4[$index]);
                            
                            $db->insert(
                                'host_addr',
                                [
                                    'host_id' => $host_id,
                                    'addr' => $ipv4,
                                    'ip' => 'v4'
                                ]
                            );
                        }

                        if (isset($nameserver_ipv6[$index]) && !empty($nameserver_ipv6[$index])) {
                            $ipv6 = normalize_v6_address($nameserver_ipv6[$index]);
                            
                            $db->insert(
                                'host_addr',
                                [
                                    'host_id' => $host_id,
                                    'addr' => $ipv6,
                                    'ip' => 'v6'
                                ]
                            );
                        }
                        
                    }
                }

                $contacts = [
                    'admin' => $data['contactAdmin'] ?? null,
                    'tech' => $data['contactTech'] ?? null,
                    'billing' => $data['contactBilling'] ?? null
                ];

                foreach ($contacts as $type => $contact) {
                    if ($contact !== null) {
                        $contact_id = $db->selectValue(
                            'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                            [$contact]
                        );

                        $contact_map_id = $db->selectRow(
                            'SELECT * FROM application_contact_map WHERE domain_id = ? AND type = ?',
                            [$domain_id, $type]
                        );

                        // Check if $contact_id is not null before update
                        if ($contact_id !== null) {
                            $db->update(
                                'application_contact_map',
                                [
                                    'contact_id' => $contact_id,
                                ],
                                [
                                    'id' => $contact_map_id['id']
                                ]
                            );
                        }
                    }
                }
           
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
   
            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
        }
    }
    
    public function applicationDeleteHost(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $data = $request->getParsedBody();
        $uri = $request->getUri()->getPath();

        if ($data['nameserver']) {
            $host_id = $db->selectValue('SELECT id FROM host WHERE name = ?',
                    [ $data['nameserver'] ]);
            $domain_id = $db->selectValue('SELECT domain_id FROM application_host_map WHERE host_id = ?',
                    [ $host_id ]);
            $domainName = $db->selectValue('SELECT name FROM domain WHERE id = ?',
                    [ $domain_id ]);
            $db->delete(
                'application_host_map',
                [
                    'host_id' => $host_id,
                    'domain_id' => $domain_id
                ]
            );
            
            $this->container->get('flash')->addMessage('success', 'Host ' . $data['nameserver'] . ' has been removed from domain successfully');

            $jsonData = json_encode([
                'success' => true,
                'redirect' => '/domain/update/'.$domainName
            ]);

            $response = new \Nyholm\Psr7\Response(
                200, // Status code
                ['Content-Type' => 'application/json'], // Headers
                $jsonData // Body
            );

            return $response;
        } else {
            $jsonData = json_encode([
                'success' => false,
                'error' => 'An error occurred while processing your request.'
            ]);

            return new \Nyholm\Psr7\Response(
                400,
                ['Content-Type' => 'application/json'],
                $jsonData
            );
        }
    }

    public function deleteApplication(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
            
                $domain = $db->selectRow('SELECT id, name, tldid, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod FROM domain WHERE name = ?',
                [ $args ]);
            
                $domainName = $domain['name'];
                $domain_id = $domain['id'];
                $tldid = $domain['tldid'];
                $registrant = $domain['registrant'];
                $crdate = $domain['crdate'];
                $exdate = $domain['exdate'];
                $registrar_id_domain = $domain['clid'];
                $crid = $domain['crid'];
                $upid = $domain['upid'];
                $trdate = $domain['trdate'];
                $trstatus = $domain['trstatus'];
                $reid = $domain['reid'];
                $redate = $domain['redate'];
                $acid = $domain['acid'];
                $acdate = $domain['acdate'];
                $rgpstatus = $domain['rgpstatus'];
                $addPeriod = $domain['addPeriod'];
                $autoRenewPeriod = $domain['autoRenewPeriod'];
                $renewPeriod = $domain['renewPeriod'];
                $renewedDate = $domain['renewedDate'];
                $transferPeriod = $domain['transferPeriod'];

                $parts = extractDomainAndTLD($domainName);
                $label = $parts['domain'];
                $domain_extension = $parts['tld'];

                $result = $db->select('SELECT id, tld FROM domain_tld');
                foreach ($result as $row) {
                    if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                        $tld_id = $row['id'];
                        break;
                    }
                }
                
                $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $result['registrar_id'];
                } else {
                    $clid = $registrar_id_domain;
                }
                
                $results = $db->select(
                    'SELECT status FROM application_status WHERE domain_id = ?',
                    [ $domain_id ]
                );

                foreach ($results as $row) {
                    $status = $row['status'];
                    if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                        $this->container->get('flash')->addMessage('error', 'It has a status that does not allow deletion, first change the status');
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }
                
                $grace_period = 30;
                
                $db->delete(
                    'application_status',
                    [
                        'domain_id' => $domain_id
                    ]
                );
                
                $db->exec(
                    'UPDATE domain SET rgpstatus = ?, delTime = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? DAY) WHERE id = ?',
                    ['redemptionPeriod', $grace_period, $domain_id]
                );
                
                $db->insert(
                    'application_status',
                    [
                        'domain_id' => $domain_id,
                        'status' => 'pendingDelete'
                    ]
                );
                    
                if ($rgpstatus) {
                    if ($rgpstatus === 'addPeriod') {
                        $addPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(crdate, INTERVAL 5 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($addPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $addPeriod, 'create');
                            $price = $returnValue['price'];
            
                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }
                            
                            try {
                                $db->beginTransaction();
                            
                                $db->exec(
                                    'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                    [$price, $clid]
                                );
                                
                                $description = "domain name is deleted by the registrar during grace addPeriod, the registry provides a credit for the cost of the registration domain $domainName for period $addPeriod MONTH";
                                $db->exec(
                                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                    [$clid, $description, $price]
                                );
                                
                                $hostIds = $db->select(
                                    'SELECT id FROM host WHERE domain_id = ?',
                                    [$domain_id]
                                );
                                
                                foreach ($hostIds as $host) {
                                    $host_id = $host['id'];

                                    // Delete operations
                                    $db->delete(
                                        'host_addr',
                                        [
                                            'host_id' => $host_id
                                        ]
                                    );
                                    $db->delete(
                                        'host_status',
                                        [
                                            'host_id' => $host_id
                                        ]
                                    );
                                    $db->delete(
                                        'application_host_map',
                                        [
                                            'host_id' => $host_id
                                        ]
                                    );
                                }
                                
                                // Delete domain related records
                                $db->delete(
                                    'application_contact_map',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'application_host_map',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain_authInfo',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'application_status',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'host',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'secdns',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain',
                                    [
                                        'id' => $domain_id
                                    ]
                                );
                                
                                $curdate_id = $db->selectValue(
                                    'SELECT id FROM statistics WHERE date = CURDATE()'
                                );

                                if (!$curdate_id) {
                                    $db->exec(
                                        'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                                    );
                                }

                                $db->exec(
                                    'UPDATE statistics SET deleted_domains = deleted_domains + 1 WHERE date = CURDATE()'
                                );
                            
                                $db->commit();
                            } catch (Exception $e) {
                                $db->rollBack();
                                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
                            }
                            $isImmediateDeletion = true;
                        }
                    } elseif ($rgpstatus === 'autoRenewPeriod') {
                        $autoRenewPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(renewedDate, INTERVAL 45 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($autoRenewPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $autoRenewPeriod, 'renew');
                            $price = $returnValue['price'];
                            
                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            $db->exec(
                                'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                [$price, $clid]
                            );
                            
                            $description = "domain name is deleted by the registrar during grace autoRenewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $autoRenewPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    } elseif ($rgpstatus === 'renewPeriod') {
                        $renewPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(renewedDate, INTERVAL 5 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($renewPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $renewPeriod, 'renew');
                            $price = $returnValue['price'];

                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            $db->exec(
                                'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                [$price, $clid]
                            );
                            
                            $description = "domain name is deleted by the registrar during grace renewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $renewPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    } elseif ($rgpstatus === 'transferPeriod') {
                        $transferPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(trdate, INTERVAL 5 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($transferPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $transferPeriod, 'renew');
                            $price = $returnValue['price'];
                            
                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            $db->exec(
                                'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                [$price, $clid]
                            );
                            
                            $description = "domain name is deleted by the registrar during grace transferPeriod, the registry provides a credit for the cost of the transfer domain $domainName for period $transferPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    }
                }
                    
                if ($isImmediateDeletion) {
                    $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' deleted successfully');
                } else {
                    $this->container->get('flash')->addMessage('info', 'Deletion process for domain ' . $domainName . ' has been initiated');
                }
                return $response->withHeader('Location', '/domains')->withStatus(302);
            } else {
                // Redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        
        //}
    }

}