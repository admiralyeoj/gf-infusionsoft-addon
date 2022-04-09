<?php

namespace Infusionsoft\Api\Rest;

class ProductService extends RestModel {

  public $full_url = 'https://api.infusionsoft.com/crm/rest/v1/products';
  public $return_key = 'products';

  public function getIndexUrl()
  {
    $url = $this->full_url.'/search';

    return $url;
  }

}
