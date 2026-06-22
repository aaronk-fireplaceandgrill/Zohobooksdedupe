<?php
class Zohobooks
{

  public function __construct($registry)
  {
    $this->session = $registry->get('session');
    $this->db = $registry->get('db');
    $this->config = $registry->get('config');
    $this->log = $registry->get('log');
  }

  // -------------------------------------------------------
  // Log helper for Zoho debugging (writes into OC error.log)
  // -------------------------------------------------------
  protected function logZoho($label, $data = null)
  {
    $message = '[ZOHO] ' . $label;

    if ($data !== null) {
      if (is_array($data) || is_object($data)) {
        $message .= ' ' . json_encode($data);
      } else {
        $message .= ' ' . (string)$data;
      }
    }

    if ($this->log) {
      $this->log->write($message);
    } else {
      error_log($message);
    }
  }

  // -------------------------------------------------------
  // Send Zoho Sync Error Email
  // -------------------------------------------------------
  protected function emailZohoError($subject, $body)
  {
    try {
      // OpenCart 3 mail usage
      $mail = new Mail($this->config->get('config_mail_engine'));
      $mail->parameter = $this->config->get('config_mail_parameter');
      $mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
      $mail->smtp_username = $this->config->get('config_mail_smtp_username');
      $mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
      $mail->smtp_port = $this->config->get('config_mail_smtp_port');
      $mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

      // TODO: change to your preferred recipient
      $mail->setTo('it@fireplaceandgrill.com');
      $mail->setFrom($this->config->get('config_email'));
      $mail->setSender($this->config->get('config_name'));
      $mail->setSubject($subject);
      $mail->setText($body);

      $mail->send();
    } catch (\Exception $e) {
      $this->logZoho('EMAIL SEND ERROR', $e->getMessage());
    }
  }

  public function updateAccessToken()
  {
    if (isset($this->session->data['zoho_books_access_token']) && $this->session->data['zoho_books_access_token'] && isset($this->session->data['zoho_books_access_token_time']) && $this->session->data['zoho_books_access_token_time'] && (time() - $this->session->data['zoho_books_access_token_time'] <= (30 * 60))) {
      $result['access_token'] = $this->session->data['zoho_books_access_token'];

      return $result;
    } else {
      $curl = curl_init();

      curl_setopt_array($curl, array(
        CURLOPT_URL => "https://accounts.zoho.com/oauth/v2/token?refresh_token=" . $this->config->get('module_opc_zoho_books_refresh_token') . "&client_id=" . $this->config->get('module_opc_zoho_books_client_id') . "&client_secret=" . $this->config->get('module_opc_zoho_books_client_secret') . "&grant_type=refresh_token",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
      ));

      $result = json_decode(curl_exec($curl), 1);

      $err = curl_error($curl);

      curl_close($curl);

      if (isset($result['access_token']) && $result['access_token']) {
        $this->session->data['zoho_books_access_token'] = $result['access_token'];

        $this->session->data['zoho_books_access_token_time'] = time();
      }

      if (!$err) {
        return $result;
      } else {
        $this->logZoho('updateAccessToken CURL error', $err);
      }
    }

    return false;
  }

  public function execute_curl($url = '', $method = '', $data = array(), $params = '')
  {
    if ($url && $method) {
      $result = $this->updateAccessToken();

      if (isset($result['access_token']) && $result['access_token']) {
        $url = $url . '?organization_id=' . $this->config->get('module_opc_zoho_books_organization_id') . $params;

        $curl = curl_init();

        if ($data) {
          $post_data = array(
            'JSONString' => json_encode($data),
          );

          curl_setopt_array($curl, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS     => $post_data,
            CURLOPT_HTTPHEADER     => array(
              "Authorization: Zoho-oauthtoken " . $result['access_token'],
            ),
          ));
        } else {
          curl_setopt_array($curl, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => array(
              "Authorization: Zoho-oauthtoken " . $result['access_token'],
            ),
          ));
        }

        $raw_response = curl_exec($curl);
        $http_code    = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err          = curl_error($curl);

        curl_close($curl);

        if ($err) {
          $this->logZoho('CURL error', array(
            'url'   => $url,
            'error' => $err,
          ));
          return false;
        }

        $response = json_decode($raw_response, true);

        return $response;
      } else {
        $this->logZoho('execute_curl: missing access token', $result);
      }
    }

    return false;
  }

  public function findZohoBooksCustomerIdByEmail($email = '', $customer_id = 0) {
    $normalized_email = strtolower(trim((string)$email));

    if (!$normalized_email) {
      return 0;
    }

    $customer_by_email = $this->execute_curl("https://books.zoho" . $this->config->get('module_opc_zoho_books_domain') . "/api/v3/contacts/", "GET", array(), "&email=" . urlencode($normalized_email));

    if (!isset($customer_by_email['contacts']) || !$customer_by_email['contacts']) {
      return 0;
    }

    foreach ($customer_by_email['contacts'] as $contact) {
      if (isset($contact['email']) && strtolower(trim((string)$contact['email'])) == $normalized_email && isset($contact['contact_id']) && $contact['contact_id']) {
        if ((int)$customer_id > 0) {
          $this->saveSyncCustomer($customer_id, $contact['contact_id']);
        }
        return $contact['contact_id'];
      }
    }

    if (isset($customer_by_email['contacts'][0]['contact_id']) && $customer_by_email['contacts'][0]['contact_id']) {
      if ((int)$customer_id > 0) {
        $this->saveSyncCustomer($customer_id, $customer_by_email['contacts'][0]['contact_id']);
      }
      return $customer_by_email['contacts'][0]['contact_id'];
    }

    return 0;
  }

  public function syncCustomerToZohoBooks($customers = array(), $contact_type = "customer")
  {
    $count = 0;

    try {
      // if ($customers && $this->config->get('module_opc_zoho_books_status')) {
      if (false) {
        foreach ($customers as $customer) {
          $this->session->data['max_customer_id'] = $customer['customer_id'];

          $zoho_customers = $this->execute_curl("https://www.zohoapis.com/books/v3/contacts/", "GET", array(), "&email=" . $customer['email']);

          if (isset($zoho_customers['contacts'][0]['email']) && $zoho_customers['contacts'][0]['email'] == $customer['email']) {
            $this->saveSyncCustomer($customer['customer_id'], $zoho_customers['contacts'][0]['contact_id']);

            $count++;
          } else {
            $contact = array(
              "first_name" => $customer['firstname'],
              "last_name" => $customer['lastname'],
              "phone" => $customer['telephone'],
              "mobile" => $customer['telephone'],
              "is_primary_contact" => true,
              "email" => $customer['email'],// added by jr
            );

            $zoho_books_customer = $this->getSyncCustomer($customer['customer_id']);

            if (isset($zoho_books_customer['zoho_books_customer_id']) && $zoho_books_customer['zoho_books_customer_id']) {
              $url = "https://www.zohoapis.com/books/v3/contacts/" . $zoho_books_customer['zoho_books_customer_id'];

              $method = "PUT";
            } else {
              $url = "https://www.zohoapis.com/books/v3/contacts";

              $method = "POST";

              $contact['email'] = $customer['email'];
            }

            $data = array(
              "contact_name" => $customer['firstname'] . ' ' . $customer['lastname'],
              "company_name" => $customer['company'],
              "contact_type" => $contact_type,
              "billing_address" => array(
                "street" => $customer['address_1'],
                "street2" => $customer['address_2'],// added by jr
                "city" => $customer['city'],
                "state" => $customer['zone_name'],
                "zip" => $customer['postcode'],
                "country" => $customer['country_name'],
              ),
              "shipping_address" => array(
                "street" => $customer['address_1'],
                "street2" => $customer['address_2'], // added by jr
                "city" => $customer['city'],
                "state" => $customer['zone_name'],
                "zip" => $customer['postcode'],
                "country" => $customer['country_name'],
              ),
              "contact_persons" => array($contact),
            );

            // $response = $this->execute_curl($url, $method, $data);
            //
            // if ($response && isset($response['contact']['contact_id']) && $response['contact']['contact_id']) {
            //   $this->saveSyncCustomer($customer['customer_id'], $response['contact']['contact_id']);
            //
            //   $count++;
            // }
          }
        }
      }
    } catch (\Exception $e) {

    }

    return $count;
  }

  public function saveSyncCustomer($customer_id = 0, $zoho_books_customer_id = 0)
  {
    if ($zoho_books_customer_id) {
      $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_customer WHERE oc_customer_id = " . (int) $customer_id);

      $this->db->query("INSERT INTO " . DB_PREFIX . "zoho_books_customer SET oc_customer_id = " . (int) $customer_id . ", zoho_books_customer_id = '" . $zoho_books_customer_id . "'");
    }
  }

  public function importCustomerFromZohoBooks()
  {
    // placeholder - original import code commented out
  }

  public function addCustomer($data)
  {
    $this->db->query("INSERT INTO " . DB_PREFIX . "customer SET customer_group_id = '1', firstname = '" . $this->db->escape($data['firstname']) . "', lastname = '" . $this->db->escape($data['lastname']) . "', email = '" . $this->db->escape($data['email']) . "', telephone = '" . $this->db->escape($data['telephone']) . "', fax = '" . $this->db->escape($data['fax']) . "', custom_field = '', newsletter = '0', salt = '" . $this->db->escape($salt = token(9)) . "', password = '" . $this->db->escape(sha1($salt . sha1($salt . sha1($data['password'])))) . "', status = '" . (int) $data['status'] . "', approved = '1', safe = '1', date_added = NOW()");

    $customer_id = $this->db->getLastId();

    if (isset($data['address']) && $data['address']) {
      foreach ($data['address'] as $address) {
        $this->db->query("INSERT INTO " . DB_PREFIX . "address SET customer_id = '" . (int) $customer_id . "', firstname = '" . $this->db->escape($data['firstname']) . "', lastname = '" . $this->db->escape($data['lastname']) . "', company = '" . $this->db->escape($address['company']) . "', address_1 = '" . $this->db->escape($address['address_1']) . "', address_2 = '" . $this->db->escape($address['address_2']) . "', city = '" . $this->db->escape($address['city']) . "', postcode = '" . $this->db->escape($address['postcode']) . "', country_id = '" . (int) $address['country_id'] . "', zone_id = '" . (int) $address['zone_id'] . "', custom_field = ''");

        if (isset($address['default'])) {
          $address_id = $this->db->getLastId();

          $this->db->query("UPDATE " . DB_PREFIX . "customer SET address_id = '" . (int) $address_id . "' WHERE customer_id = '" . (int) $customer_id . "'");
        }
      }
    }

    return $customer_id;
  }

  public function deleteCustomerFromZohoBooks($customers = array())
  {
    $count = 0;

    try {
      if ($customers) {
        foreach ($customers as $customer) {
          $zoho_books_customer = $this->getSyncCustomer($customer);

          if (isset($zoho_books_customer['zoho_books_customer_id']) && $zoho_books_customer['zoho_books_customer_id']) {
            $response = $this->execute_curl("https://www.zohoapis.com/books/v3/contacts/" . $zoho_books_customer['zoho_books_customer_id'], "DELETE");

            if ($response && isset($response['code']) && $response['code'] == 0) {
              $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_customer WHERE oc_customer_id = " . (int) $customer);

              $count++;
            }
          }
        }
      }
    } catch (\Exception $e) {

    }

    return $count;
  }

  public function syncProductsToZohoBooks($products = array())
  {
    $count = 0;

    try {
      if ($products) {
        foreach ($products as $product) {
          $this->session->data['max_product_id'] = $product['product_id'];

          $zoho_products = $this->execute_curl("https://www.zohoapis.com/books/v3/items", "GET", array(), "&name=" . $product['model']);

          if (isset($zoho_products['items'][0]['name']) && $zoho_products['items'][0]['name'] == $product['model']) {
            $this->saveSyncProduct($product['product_id'], $zoho_products['items'][0]['item_id']);

            $count++;
          } else {
            $data = array(
              "group_name" => $product['name'],
              "unit" => "qty",
              "item_type" => "inventory", //JR
              "product_type" => "goods",
              "description" => strip_tags(html_entity_decode($product['name'])),//JR
              "name" => $product['model'],
              "rate" => $product['price'],
              "sku" => $product['sku'],
              "upc" => $product['upc'],
              "ean" => $product['ean'],
              "isbn" => $product['isbn'],
              "purchase_description" => strip_tags(html_entity_decode($product['name'])),
            );

            $zoho_books_product = $this->getSyncProduct($product['product_id']);

            if (isset($zoho_books_product['zoho_books_product_id']) && $zoho_books_product['zoho_books_product_id']) {
              $url = "https://www.zohoapis.com/books/v3/items/" . $zoho_books_product['zoho_books_product_id'];

              $method = "PUT";
            } else {
              $url = "https://www.zohoapis.com/books/v3/items";

              $method = "POST";

              $data['initial_stock'] = $product['quantity'];

              $data['initial_stock_rate'] = $product['price'];
            }

            // $response = $this->execute_curl($url, $method, $data);
            //
            // if ($response && isset($response['item']['item_id']) && $response['item']['item_id']) {
            //   $this->saveSyncProduct($product['product_id'], $response['item']['item_id']);
            //
            //   $count++;
            // }
          }
        }
      }
    } catch (\Exception $e) {

    }

    return $count;
  }

  public function saveSyncProduct($product_id = 0, $zoho_books_product_id = 0)
  {
    // original implementation commented out
  }

  public function importProductFromZohoBooks()
  {
    $count = 0;
    // original import logic commented out
    return $count;
  }

  public function addProduct($data)
  {
    $this->db->query("INSERT INTO " . DB_PREFIX . "product SET model = '" . $this->db->escape($data['sku']) . "', sku = '" . $this->db->escape($data['sku']) . "', upc = '" . $data['upc'] . "', ean = '" . $data['ean'] . "', jan = '', isbn = '" . $data['isbn'] . "', mpn = '" . $data['mpn'] . "', location = '', quantity = '" . (int) $data['quantity'] . "', minimum = '1', subtract = '1', stock_status_id = '7', date_available = NOW(), manufacturer_id = '', shipping = '1', price = '" . (float) $data['price'] . "', points = '0', weight = '0', weight_class_id = '1', length = '0', width = '0', height = '0', length_class_id = '1', status = '" . (int) $data['status'] . "', tax_class_id = '0', sort_order = '0', date_added = NOW()");

    $product_id = $this->db->getLastId();

    $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = '" . (int) $product_id . "', language_id = '" . (int) $this->config->get('config_language_id') . "', name = '" . $this->db->escape($data['name']) . "', description = '" . $this->db->escape($data['description']) . "', tag = '', meta_title = '" . $this->db->escape($data['sku']) . "', meta_description = '', meta_keyword = ''");

    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id = '" . (int) $product_id . "', store_id = '0'");

    return $product_id;
  }

  public function deleteProductFromZohoBooks($products = array())
  {
    $count = 0;

    try {
      if ($products) {
        foreach ($products as $product) {
          $zoho_books_product = $this->getSyncProduct($product);

          if (isset($zoho_books_product['zoho_books_product_id']) && $zoho_books_product['zoho_books_product_id']) {
            $response = $this->execute_curl("https://www.zohoapis.com/books/v3/items/" . $zoho_books_product['zoho_books_product_id'], "DELETE");

            if ($response && isset($response['code']) && $response['code'] == 0) {
              $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_product WHERE oc_product_id = " . (int) $product);

              $count++;
            }
          }
        }
      }
    } catch (\Exception $e) {

    }

    return $count;
  }

  public function syncOrdersToZohoBooks($orders = array())
  {
    $count = 0;

    try {

      if ($orders) {
        foreach ($orders as $order) {
          $this->session->data['max_order_id'] = $order['order_id'];

          if (isset($order['customer_id'])) {

            $zoho_books_customer_id = 0;

            $zoho_books_customer = $this->getSyncCustomer($order['customer_id']);

            if ($zoho_books_customer && isset($zoho_books_customer['zoho_books_customer_id']) && $zoho_books_customer['zoho_books_customer_id']) {
              $zoho_books_customer_id = $zoho_books_customer['zoho_books_customer_id'];
            } else {
              if ($order['payment_company'] || $order['shipping_company']) {
                $this->syncCustomerToZohoBooks($this->getCustomersToSync(array('customer_id' => $order['customer_id'])), 'business');
              } else {
                $this->syncCustomerToZohoBooks($this->getCustomersToSync(array('customer_id' => $order['customer_id'])));
              }

              $zoho_books_customer = $this->getSyncCustomer($order['customer_id']);

              if ($zoho_books_customer && isset($zoho_books_customer['zoho_books_customer_id']) && $zoho_books_customer['zoho_books_customer_id']) {
                $zoho_books_customer_id = $zoho_books_customer['zoho_books_customer_id'];
              }
            }

            if (!$zoho_books_customer_id) {
              $zoho_books_customer_id = $this->findZohoBooksCustomerIdByEmail($order['email'], $order['customer_id']);

              if (!$zoho_books_customer_id) {
                if ($order['payment_company'] || $order['shipping_company']) {
                  $contact_type = "business";
                } else {
                  $contact_type = "customer";
                }

                $data = array(
                  "contact_name" => $order['firstname'] . ' ' . $order['lastname'],
                  "company_name" => $order['payment_company'] ? $order['payment_company'] : $order['shipping_company'],
                  "contact_type" => 'customer',
                  "customer_sub_type" => $order['payment_company'] ? "business" : "",
                  "billing_address" => array(
                    "street" => $order['payment_address_1'],
                    "street2" => $order['payment_address_2'],
                    "city" => $order['payment_city'],
                    "state" => $order['payment_zone'],
                    "zip" => $order['payment_postcode'],
                    "country" => $order['payment_country'],
                    "phone" => trim($order['telephone']),
                  ),
                  "shipping_address" => array(
                    "street" => $order['shipping_address_1'],
                    "street2" => $order['shipping_address_2'],
                    "city" => $order['shipping_city'],
                    "state" => $order['shipping_zone'],
                    "zip" => $order['shipping_postcode'],
                    "country" => $order['shipping_country'],
                    "phone" => trim($order['telephone']),
                  ),
                  "contact_persons" => array(
                    array(
                      "first_name" => $order['firstname'],
                      "last_name" => $order['lastname'],
                      "phone" => trim($order['telephone']),
                      "mobile" => trim($order['telephone']),
                      "is_primary_contact" => true,
                      "email" => $order['email'],
                    )
                  ),
                );

                $response = $this->execute_curl("https://www.zohoapis.com/books/v3/contacts", "POST", $data);

                if ($response && isset($response['contact']['contact_id']) && $response['contact']['contact_id']) {
                  $zoho_books_customer_id = $response['contact']['contact_id'];
                }
              }
            }

            if ($zoho_books_customer_id) {
              $shipping_address_id = 0;
              $billing_address_id = 0;

              $allAddresses = $this->execute_curl("https://www.zohoapis.com/books/v3/contacts/" . $zoho_books_customer_id . "/address", "GET");

              if (isset($allAddresses['addresses']) && $allAddresses['addresses']) {
                foreach ($allAddresses['addresses'] as $address) {
                  if ($address['address'] == $order['shipping_address_1'] && $address['city'] == $order['shipping_city'] && $address['zip'] == $order['shipping_postcode']) {
                    $shipping_address_id = $address['address_id'];
                    break;
                  }
                }

                foreach ($allAddresses['addresses'] as $address) {
                  if ($address['address'] == $order['payment_address_1'] && $address['city'] == $order['payment_city'] && $address['zip'] == $order['payment_postcode']) {
                    $billing_address_id = $address['address_id'];
                    break;
                  }
                }
              }

              // name match for attention logic
              if ($order['shipping_firstname'] == $order['firstname'] && $order['shipping_lastname'] == $order['lastname']) {
                $attn = '';
              } else {
                $attn = $order['shipping_firstname'] . ' ' . $order['shipping_lastname'];
              }

              if (!$shipping_address_id) {
                $data = array(
                  "attention" => $attn,
                  "address" => $order['shipping_address_1'],
                  "street2" => $order['shipping_address_2'],
                  "city" => $order['shipping_city'],
                  "state" => $order['shipping_zone'],
                  "zip" => $order['shipping_postcode'],
                  "country" => $order['shipping_country'],
                  "phone" => trim($order['telephone']),
                );

                $response = $this->execute_curl("https://www.zohoapis.com/books/v3/contacts/" . $zoho_books_customer_id . "/address", "POST", $data);

                if (isset($response['address_info']['address_id']) && $response['address_info']['address_id']) {
                  $shipping_address_id = $response['address_info']['address_id'];

                  if (isset($address['shipping_address_1']) && $address['shipping_address_1'] == $order['payment_address_1'] && $address['shipping_city'] == $order['payment_city'] && $address['shipping_postcode'] == $order['payment_postcode']) {
                    $billing_address_id = $shipping_address_id;
                  }
                }
              }

              if (!$billing_address_id) {
                $data = array(
                  "attention" => $order['payment_firstname'] . ' ' . $order['payment_lastname'] . ' ' . $order['payment_company'],
                  "address" => $order['payment_address_1'],
                  "street2" => $order['payment_address_2'],
                  "city" => $order['payment_city'],
                  "state" => $order['payment_zone'],
                  "zip" => $order['payment_postcode'],
                  "country" => $order['payment_country'],
                  "phone" => trim($order['telephone']),
                );

                $response = $this->execute_curl("https://www.zohoapis.com/books/v3/contacts/" . $zoho_books_customer_id . "/address", "POST", $data);

                if (isset($response['address_info']['address_id']) && $response['address_info']['address_id']) {
                  $billing_address_id = $response['address_info']['address_id'];
                }
              }

              $line_items = array();
              $item_order = 0;

              if ($order['products']) {
                foreach ($order['products'] as $product) {
                  $zoho_books_product_id = 0;
                  $item_order++;

                  $zoho_products = $this->execute_curl("https://www.zohoapis.com/books/v3/items", "GET", array(), "&name=" . $product['model']);

                  if (isset($zoho_products['items'][0]['name']) && $zoho_products['items'][0]['name'] == $product['model']) {
                    $zoho_books_product_id = $zoho_products['items'][0]['item_id'];
                  } else {
                    $data = array(
                      "group_name" => $product['name'],
                      "unit" => "qty",
                      "item_type" => "inventory",
                      "product_type" => "goods",
                      "initial_stock" => $product['quantity'],
                      "initial_stock_rate" => $product['price'],
                      "description" => strip_tags(html_entity_decode($product['name'])),
                      "name" => $product['model'],
                      "rate" => $product['price'],
                      "sku" => $product['model'],
                      "purchase_description" => strip_tags(html_entity_decode($product['name'])),
                    );

                    $response = $this->execute_curl("https://www.zohoapis.com/books/v3/items", "POST", $data);

                    if ($response && isset($response['item']['item_id']) && $response['item']['item_id']) {
                      $zoho_books_product_id = $response['item']['item_id'];
                    }
                  }

                  $line_items[] = array(
                    "item_order" => $item_order,
                    "item_id" => $zoho_books_product_id,
                    "name" => $product['model'],
                    "description" => $product['name'],
                    "tags" => array(
                      array(
                        "tag_id" => 2036335000000000333,
                        "tag_option_id" => 2036335000001642368
                      ),
                      array(
                        "tag_id" => 2036335000000000339,
                        "tag_option_id" => 2036335000004091727
                      ),
                    ),
                    "rate" => $product['price'],
                    "quantity" => $product['quantity'],
                    "unit" => "qty",
                    "item_total" => $product['total'],
                    "tax_id" => 2036335000002175005,
                    "avatax_tax_code" => "P0000000",//jr
                  );
                }
              }

              $shipping = 0;
              $discount = 0;
              $coupon_code = '';

              if ($order['order_totals']) {
                foreach ($order['order_totals'] as $order_total) {
                  if ($order_total['code'] == 'shipping') {
                    $shipping += $order_total['value'];
                  }

                  if ($order_total['code'] == 'coupon' || $order_total['code'] == 'discount' || $order_total['code'] == 'gift') {
                    $discount += abs($order_total['value']);
                    $coupon_code = TRIM(str_replace(")", "", str_replace("(", "", str_replace("Referral code", "", str_replace("Coupon", "", $order_total['title'])))));
                  }
                }

                $line_items[] = array(
                  "item_order" => $item_order + 1,
                  "item_id" => 2036335000002917009,
                  "name" => "SHIP",//jr
                  "discount_type" => "line_items",
                  "is_discounted" => "false",
                  "avatax_tax_code" => "FR020100",
                  "description" => "Shipping Charges",
                  "tags" => array(
                    array(
                      "tag_id" => 2036335000000000333,
                      "tag_option_id" => 2036335000001642368
                    )
                  ),
                  "rate" => $shipping,
                  "quantity" => 1,
                  "unit" => "qty",
                  "item_total" => $shipping,
                );
              }

              $zoho_books_order = $this->getSyncOrder($order['order_id']);

              if (isset($zoho_books_order['zoho_books_order_id']) && $zoho_books_order['zoho_books_order_id']) {
                if ($this->config->get('module_opc_zoho_books_order_mapping') == 2) {
                  $url = "https://www.zohoapis.com/books/v3/invoices/" . $zoho_books_order['zoho_books_order_id'];
                } elseif ($this->config->get('module_opc_zoho_books_order_mapping') == 3) {
                  $url = "https://www.zohoapis.com/books/v3/estimates/" . $zoho_books_order['zoho_books_order_id'];
                } else {
                  $url = "https://www.zohoapis.com/books/v3/salesorders/" . $zoho_books_order['zoho_books_order_id'];
                }

                $method = "PUT";
              } else {
                if ($this->config->get('module_opc_zoho_books_order_mapping') == 2) {
                  $url = "https://www.zohoapis.com/books/v3/invoices";
                } elseif ($this->config->get('module_opc_zoho_books_order_mapping') == 3) {
                  $url = "https://www.zohoapis.com/books/v3/estimates";
                } else {
                  $url = "https://www.zohoapis.com/books/v3/salesorders";
                }

                $method = "POST";
              }

              // xpayment module support
              $payment_code = $order['payment_code'];
              if (strpos($payment_code, 'x') !== false) {
                $payment_method = $order['payment_method'];
              } else {
                $payment_method = '';
              }

              // Total for custom field
              $total = $this->getOrderTotal($order['order_id']);

              $custom_fields = array(
                array(
                  "customfield_id" => 2036335000025913395,
                  "value" => $payment_method
                ),
                array(
                  "customfield_id" => 2036335000039204371,
                  "value" => $coupon_code
                ),
                array(
                  "customfield_id" => 2036335000071629159,
                  "value" => "DIM"
                ),
                array(
                  "customfield_id" => 2036335000076412881,
                  "value" => $total
                ),
                array(
                  "customfield_id" => 2036335000084366147,
                  "value" => $order['forwarded_ip']
                )
              );

              $howdidyouhearaboutus = $this->getHowDidYouHearAboutUsValue($order['order_id']);

              if ($howdidyouhearaboutus !== '') {
                $custom_fields[] = array(
                  "customfield_id" => 2036335000042667759,
                  "value" => $howdidyouhearaboutus
                );
              }

              $data = array(
                "customer_id" => $zoho_books_customer_id,
                "date" => date("Y-m-d", strtotime($order['date_added'])),
                "reference_number" => $order['order_id'],
                "line_items" => $line_items,
                "notes" => $order['comment'],
                "custom_fields" => $custom_fields,
                "discount" => $discount,
                "discount_type" => "entity_level",
                "delivery_method" => $order['shipping_method'],
                "template_id" => 2036335000002752001
              );

              if ($this->config->get('module_opc_zoho_books_order_mapping') == 2) {
                $data['invoice_number'] = $order['order_id'];
              } elseif ($this->config->get('module_opc_zoho_books_order_mapping') == 3) {
                $data['estimate_number'] = $order['order_id'];
              } else {
                $data['salesorder_number'] = $order['order_id'];
              }

              if ($shipping_address_id) {
                $data['shipping_address_id'] = $shipping_address_id;
              }

              if ($billing_address_id) {
                $data['billing_address_id'] = $billing_address_id;
              }

              $salesagent = $this->db->query("SELECT CONCAT(sa.firstname, ' ', sa.lastname) AS salesagent FROM " . DB_PREFIX . "salesagent sa WHERE sa.salesagent_id = (SELECT salesagent_id  FROM `" . DB_PREFIX . "salesagent_order` WHERE order_id = " . $order['order_id'] . ")")->row;

              if (isset($salesagent['salesagent']) && $salesagent['salesagent']) {
                $data['salesperson_name'] = $salesagent['salesagent'];
              }

              $response = $this->execute_curl($url, $method, $data, "&ignore_auto_number_generation=true");

              if (!$response || (isset($response['code']) && $response['code'] != 0)) {
                $this->emailZohoError(
                  'Zoho Sync Error: Order Failed',
                  "Order ID: " . $order['order_id'] . "\n\n" .
                  "Request URL: $url\n\n" .
                  "Response:\n" . print_r($response, true)
                );
              }

              if ($this->config->get('module_opc_zoho_books_order_mapping') == 2) {
                if ($response && isset($response['invoice']['invoice_id']) && $response['invoice']['invoice_id']) {
                  $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_order WHERE oc_order_id = " . (int) $order['order_id']);

                  $this->db->query("INSERT INTO " . DB_PREFIX . "zoho_books_order SET oc_order_id = " . (int) $order['order_id'] . ", zoho_books_order_id = '" . $response['invoice']['invoice_id'] . "'");

                  $count++;
                } else {
                  
                }
              } elseif ($this->config->get('module_opc_zoho_books_order_mapping') == 3) {
                if ($response && isset($response['estimate']['estimate_id']) && $response['estimate']['estimate_id']) {
                  $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_order WHERE oc_order_id = " . (int) $order['order_id']);

                  $this->db->query("INSERT INTO " . DB_PREFIX . "zoho_books_order SET oc_order_id = " . (int) $order['order_id'] . ", zoho_books_order_id = '" . $response['estimate']['estimate_id'] . "'");

                  $count++;
                } else {

                }
              } else {
                if ($response && isset($response['salesorder']['salesorder_id']) && $response['salesorder']['salesorder_id']) {
                  $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_order WHERE oc_order_id = " . (int) $order['order_id']);

                  $this->db->query("INSERT INTO " . DB_PREFIX . "zoho_books_order SET oc_order_id = " . (int) $order['order_id'] . ", zoho_books_order_id = '" . $response['salesorder']['salesorder_id'] . "'");

                  $count++;
                } else {

                }
              }
            }
          }
        }
      }
    } catch (\Exception $e) {
      $this->logZoho('Error in syncOrdersToZohoBooks', $e->getMessage());
      error_log('Error in syncOrdersToZohoBooks: ' . $e);
    }

    return $count;
  }

  public function getOrderTotal($order_id) {
    $order_total_query = $this->db->query("SELECT total FROM `" . DB_PREFIX . "order` o WHERE o.order_id = '" . (int) $order_id . "'");
    if ($order_total_query->num_rows) {
      return $order_total_query->row['total'];
    }
  }

  public function getHowDidYouHearAboutUsValue($order_id = 0) {
    $query = $this->db->query("SELECT h.value FROM `" . DB_PREFIX . "order` o LEFT JOIN `" . DB_PREFIX . "howdidyouhearaboutus` h ON h.id = o.howdidyouhearaboutus_id WHERE o.order_id = " . (int)$order_id . " LIMIT 1");

    return isset($query->row['value']) ? trim($query->row['value']) : '';
  }

  public function deleteOrderFromZohoBooks($orders = array())
  {
    $count = 0;

    try {
      if ($orders) {
        foreach ($orders as $order) {
          $zoho_books_order = $this->getSyncOrder($order);

          if (isset($zoho_books_order['zoho_books_order_id']) && $zoho_books_order['zoho_books_order_id']) {
            if ($this->config->get('module_opc_zoho_books_order_mapping') == 2) {
              $url = "https://www.zohoapis.com/books/v3/invoices/" . $zoho_books_order['zoho_books_order_id'];
            } elseif ($this->config->get('module_opc_zoho_books_order_mapping') == 3) {
              $url = "https://www.zohoapis.com/books/v3/estimates/" . $zoho_books_order['zoho_books_order_id'];
            } else {
              $url = "https://www.zohoapis.com/books/v3/salesorders/" . $zoho_books_order['zoho_books_order_id'];
            }

            $response = $this->execute_curl($url, "DELETE");

            if ($response && isset($response['code']) && $response['code'] == 0) {
              $this->db->query("DELETE FROM " . DB_PREFIX . "zoho_books_order WHERE oc_order_id = " . (int) $order);

              $count++;
            }
          }
        }
      }
    } catch (\Exception $e) {

    }

    return $count;
  }

  public function getSyncOrder($order_id = 0)
  {
    return $this->db->query("SELECT * FROM " . DB_PREFIX . "zoho_books_order WHERE oc_order_id = " . (int) $order_id)->row;
  }

  public function getCustomersToSync($data = array())
  {
    $sql = "SELECT c.customer_id, c.firstname, c.lastname, c.email, c.telephone, c.status, c.fax, a.company, a.address_1, a.address_2, a.city, a.postcode, co.name as country_name, z.name as zone_name FROM " . DB_PREFIX . "customer c LEFT JOIN " . DB_PREFIX . "customer_group_description cgd ON (c.customer_group_id = cgd.customer_group_id) LEFT JOIN " . DB_PREFIX . "address a ON (c.address_id = a.address_id) LEFT JOIN " . DB_PREFIX . "country co ON (a.country_id = co.country_id) LEFT JOIN " . DB_PREFIX . "zone z ON (a.zone_id = z.zone_id) WHERE cgd.language_id = '" . (int) $this->config->get('config_language_id') . "'";

    if (isset($data['customer_id']) && $data['customer_id']) {
      $sql .= " AND c.customer_id = " . (int) $data['customer_id'];
    } else {
      if (isset($this->session->data['max_customer_id']) && $this->session->data['max_customer_id']) {
        $sql .= " AND c.customer_id > " . $this->session->data['max_customer_id'];
      }

      $sql .= " AND c.customer_id NOT IN (SELECT oc_customer_id FROM " . DB_PREFIX . "zoho_books_customer)";
    }

    $sql .= " ORDER BY c.customer_id ASC ";

    if (isset($data['start']) || isset($data['limit'])) {
      if ($data['start'] < 0) {
        $data['start'] = 0;
      }

      if ($data['limit'] < 1) {
        $data['limit'] = 20;
      }

      $sql .= " LIMIT " . (int) $data['start'] . "," . (int) $data['limit'];
    }

    $query = $this->db->query($sql);

    return $query->rows;
  }

  public function getSyncCustomer($customer_id = 0)
  {
    return $this->db->query("SELECT * FROM " . DB_PREFIX . "zoho_books_customer WHERE oc_customer_id = " . (int) $customer_id)->row;
  }

  public function getProductsToSync($data = array())
  {
    $sql = "SELECT * FROM " . DB_PREFIX . "product p LEFT JOIN " . DB_PREFIX . "product_description pd ON (p.product_id = pd.product_id) WHERE pd.language_id = '" . (int) $this->config->get('config_language_id') . "'";

    if (isset($data['product_id']) && $data['product_id']) {
      $sql .= " AND p.product_id = " . (int) $data['product_id'];
    } else {
      if (isset($this->session->data['max_product_id']) && $this->session->data['max_product_id']) {
        $sql .= " AND p.product_id > " . $this->session->data['max_product_id'];
      }

      $sql .= " AND p.product_id NOT IN (SELECT oc_product_id FROM " . DB_PREFIX . "zoho_books_product)";
    }

    $sql .= " ORDER BY p.product_id ASC ";

    if (isset($data['start']) || isset($data['limit'])) {
      if ($data['start'] < 0) {
        $data['start'] = 0;
      }

      if ($data['limit'] < 1) {
        $data['limit'] = 20;
      }

      $sql .= " LIMIT " . (int) $data['start'] . "," . (int) $data['limit'];
    }

    $query = $this->db->query($sql);

    return $query->rows;
  }

  public function getOrdersToSync($data = array())
  {
    $sql = "SELECT o.order_id, CONCAT(o.firstname, ' ', o.lastname) AS customer, (SELECT os.name FROM " . DB_PREFIX . "order_status os WHERE os.order_status_id = o.order_status_id AND os.language_id = '" . (int) $this->config->get('config_language_id') . "') AS order_status, o.shipping_code, o.total, o.currency_code, o.currency_value, o.date_added, o.date_modified, (SELECT CONCAT(sa.firstname, ' ', sa.lastname) FROM " . DB_PREFIX . "salesagent sa WHERE sa.salesagent_id = o.salesagent_id) AS salesagent FROM `" . DB_PREFIX . "order` o";

    if (!empty($data['filter_order_status'])) {
      $implode = array();

      foreach ($data['filter_order_status'] as $order_status_id) {
        $implode[] = "o.order_status_id = '" . (int) $order_status_id . "'";
      }

      if ($implode) {
        $sql .= " WHERE (" . implode(" OR ", $implode) . ")";
      }
    } elseif (isset($data['filter_order_status_id']) && $data['filter_order_status_id'] !== '') {
      $sql .= " WHERE o.order_status_id = '" . (int) $data['filter_order_status_id'] . "'";
    } else {
      $sql .= " WHERE o.order_status_id > '0'";
    }

    if (isset($data['order_id']) && $data['order_id']) {
      $sql .= " AND o.order_id = " . (int) $data['order_id'];
    } else {
      if (isset($this->session->data['max_order_id']) && $this->session->data['max_order_id']) {
        $sql .= " AND o.order_id > " . $this->session->data['max_order_id'];
      }

      $sql .= " AND o.order_id NOT IN (SELECT oc_order_id FROM " . DB_PREFIX . "zoho_books_order)";
    }

    $sql .= " ORDER BY o.order_id ASC ";

    if (isset($data['start']) || isset($data['limit'])) {
      if ($data['start'] < 0) {
        $data['start'] = 0;
      }

      if ($data['limit'] < 1) {
        $data['limit'] = 20;
      }

      $sql .= " LIMIT " . (int) $data['start'] . "," . (int) $data['limit'];
    }

    $query = $this->db->query($sql);

    return $query->rows;
  }

  public function getOrder($order_id)
  {
    $order_query = $this->db->query("SELECT *, (SELECT CONCAT(c.firstname, ' ', c.lastname) FROM " . DB_PREFIX . "customer c WHERE c.customer_id = o.customer_id) AS customer, (SELECT os.name FROM " . DB_PREFIX . "order_status os WHERE os.order_status_id = o.order_status_id AND os.language_id = '" . (int) $this->config->get('config_language_id') . "') AS order_status FROM `" . DB_PREFIX . "order` o WHERE o.order_id = '" . (int) $order_id . "'");

    if ($order_query->num_rows) {
      $country_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "country` WHERE country_id = '" . (int) $order_query->row['payment_country_id'] . "'");

      if ($country_query->num_rows) {
        $payment_iso_code_2 = $country_query->row['iso_code_2'];
        $payment_iso_code_3 = $country_query->row['iso_code_3'];
      } else {
        $payment_iso_code_2 = '';
        $payment_iso_code_3 = '';
      }

      $zone_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone` WHERE zone_id = '" . (int) $order_query->row['payment_zone_id'] . "'");

      if ($zone_query->num_rows) {
        $payment_zone_code = $zone_query->row['code'];
      } else {
        $payment_zone_code = '';
      }

      $country_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "country` WHERE country_id = '" . (int) $order_query->row['shipping_country_id'] . "'");

      if ($country_query->num_rows) {
        $shipping_iso_code_2 = $country_query->row['iso_code_2'];
        $shipping_iso_code_3 = $country_query->row['iso_code_3'];
      } else {
        $shipping_iso_code_2 = '';
        $shipping_iso_code_3 = '';
      }

      $zone_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone` WHERE zone_id = '" . (int) $order_query->row['shipping_zone_id'] . "'");

      if ($zone_query->num_rows) {
        $shipping_zone_code = $zone_query->row['code'];
      } else {
        $shipping_zone_code = '';
      }

      $reward = 0;

      $order_product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int) $order_id . "'");

      foreach ($order_product_query->rows as $product) {
        $reward += $product['reward'];
      }

      if ($order_query->row['affiliate_id']) {
        $affiliate_id = $order_query->row['affiliate_id'];
      } else {
        $affiliate_id = 0;
      }

      return array(
        'order_id' => $order_query->row['order_id'],
        'invoice_no' => $order_query->row['invoice_no'],
        'invoice_prefix' => $order_query->row['invoice_prefix'],
        'store_id' => $order_query->row['store_id'],
        'store_name' => $order_query->row['store_name'],
        'store_url' => $order_query->row['store_url'],
        'customer_id' => $order_query->row['customer_id'],
        'customer' => $order_query->row['customer'],
        'customer_group_id' => $order_query->row['customer_group_id'],
        'firstname' => $order_query->row['firstname'],
        'lastname' => $order_query->row['lastname'],
        'email' => $order_query->row['email'],
        'telephone' => $order_query->row['telephone'],
        'fax' => $order_query->row['fax'],
        'custom_field' => json_decode($order_query->row['custom_field'], true),
        'payment_firstname' => $order_query->row['payment_firstname'],
        'payment_lastname' => $order_query->row['payment_lastname'],
        'payment_company' => $order_query->row['payment_company'],
        'payment_address_1' => $order_query->row['payment_address_1'],
        'payment_address_2' => $order_query->row['payment_address_2'],
        'payment_postcode' => $order_query->row['payment_postcode'],
        'payment_city' => $order_query->row['payment_city'],
        'payment_zone_id' => $order_query->row['payment_zone_id'],
        'payment_zone' => $order_query->row['payment_zone'],
        'payment_zone_code' => $payment_zone_code,
        'payment_country_id' => $order_query->row['payment_country_id'],
        'payment_country' => $order_query->row['payment_country'],
        'payment_iso_code_2' => $payment_iso_code_2,
        'payment_iso_code_3' => $payment_iso_code_3,
        'payment_address_format' => $order_query->row['payment_address_format'],
        'payment_custom_field' => json_decode($order_query->row['payment_custom_field'], true),
        'payment_method' => $order_query->row['payment_method'],
        'payment_code' => $order_query->row['payment_code'],
        'shipping_firstname' => $order_query->row['shipping_firstname'],
        'shipping_lastname' => $order_query->row['shipping_lastname'],
        'shipping_company' => $order_query->row['shipping_company'],
        'shipping_address_1' => $order_query->row['shipping_address_1'],
        'shipping_address_2' => $order_query->row['shipping_address_2'],
        'shipping_postcode' => $order_query->row['shipping_postcode'],
        'shipping_city' => $order_query->row['shipping_city'],
        'shipping_zone_id' => $order_query->row['shipping_zone_id'],
        'shipping_zone' => $order_query->row['shipping_zone'],
        'shipping_zone_code' => $shipping_zone_code,
        'shipping_country_id' => $order_query->row['shipping_country_id'],
        'shipping_country' => $order_query->row['shipping_country'],
        'shipping_iso_code_2' => $shipping_iso_code_2,
        'shipping_iso_code_3' => $shipping_iso_code_3,
        'shipping_address_format' => $order_query->row['shipping_address_format'],
        'shipping_custom_field' => json_decode($order_query->row['shipping_custom_field'], true),
        'shipping_method' => $order_query->row['shipping_method'],
        'shipping_code' => $order_query->row['shipping_code'],
        'comment' => $order_query->row['comment'],
        'total' => $order_query->row['total'],
        'reward' => $reward,
        'order_status_id' => $order_query->row['order_status_id'],
        'order_status' => $order_query->row['order_status'],
        'affiliate_id' => $order_query->row['affiliate_id'],
        'commission' => $order_query->row['commission'],
        'language_id' => $order_query->row['language_id'],
        'currency_id' => $order_query->row['currency_id'],
        'currency_code' => $order_query->row['currency_code'],
        'currency_value' => $order_query->row['currency_value'],
        'ip' => $order_query->row['ip'],
        'forwarded_ip' => $order_query->row['forwarded_ip'],
        'user_agent' => $order_query->row['user_agent'],
        'accept_language' => $order_query->row['accept_language'],
        'date_added' => $order_query->row['date_added'],
        'date_modified' => $order_query->row['date_modified']
      );
    } else {
      return;
    }
  }

  public function getOrderProducts($order_id)
  {
    $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int) $order_id . "'");

    return $query->rows;
  }

  public function getOrderTotals($order_id)
  {
    $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int) $order_id . "' ORDER BY sort_order");

    return $query->rows;
  }

  public function getSyncProduct($product_id = 0)
  {
    return $this->db->query("SELECT * FROM " . DB_PREFIX . "zoho_books_product WHERE oc_product_id = " . (int) $product_id)->row;
  }
}
