<?php

class AlbaException extends Exception
{
    public function __construct($message, $code)
    {
        $this->code = $code;
        parent::__construct($message);
    }
}


class AlbaService {
    const BASE_URL = 'https://partner.rficb.ru/';
    const CURL_TIMEOUT = 45;

    /**
     * @param integer $service_id идентификатор сервиса
     * @param string $secret секретный ключ сервиса
     */
    public function __construct($service_id, $secret)
    {
        $this->service_id = $service_id;
        $this->secret = $secret;
    }

    /**
     * @brief Логгирование событий, предназначено для переопределения
     * @param string $level уровень debug, info или error
     * @param string $message сообщение
     */
    protected function _log($level, $message)
    {
    }

    /**
     * @brief Построение запроса RFC 3986
     * @param array $queryData параметры запроса
     * @param string $argSeparator разделитель
     * @return string
     */
    protected function _http_build_query_rfc_3986($queryData, $argSeparator='&')
    {
        $r = '';
        $queryData = (array) $queryData;
        if(!empty($queryData))
            {
                foreach($queryData as $k=>$queryVar)
                    {
                        $r .= $argSeparator;
                        $r .= $k;
                        $r .= '=';
                        $r .= rawurlencode($queryVar);
                    }
            }
        return trim($r,$argSeparator);
    }

    /**
     * @brief Формирование подписи по всем полям HTTP запроса
     * @param string $method метод: GET, POST, PUT, DELETE
     * @param string $url URL запроса без параметров
     * @param string $params параметры GET и POST
     * @param string $secretKey секретный ключ
     * @param string $skipPort если в url нестандартный порт, участвует ли он в подписи
     * @return string
     */
    public function sign($method, $url, $params, $secretKey, $skipPort=False)
    {
        ksort($params, SORT_LOCALE_STRING);

        $url = strtolower($url);
        $urlParsed = parse_url($url);
        $path = isset($urlParsed['path'])?
            rtrim($urlParsed['path'], '/\\').'/': "";
        $host = isset($urlParsed['host'])? $urlParsed['host']: "";
        if (isset($urlParsed['port']) && $urlParsed['port'] != 80) {
            if (!$skipPort) {
                $host .= ":{$urlParsed['port']}";
            }
        }

        $method = strtoupper($method);

        $data = implode("\n",
                        array(
                            $method,
                            $host,
                            $path,
                            $this->_http_build_query_rfc_3986($params)
                        )
        );

        $signature = base64_encode(
            hash_hmac("sha256",
                      "{$data}",
                      "{$secretKey}",
                      TRUE
            )
        );

        return $signature;
    }

    /**
     * @brief HTTP запрос к РФИ API
     * @param string $url адрес запроса
     * @param string $post параметры запроса
     * @throw AlbaException
     * @return array
     */
    protected function _curl($url, $post=False)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, static::CURL_TIMEOUT);

        if ($post) {
            $query = http_build_query($post);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            $this->_log('info', "Отправлен POST запрос: $url, с параметрами: $query");
        } else {
            $this->_log('info', "Отправлен GET запрос: $url");
        }
        $result = curl_exec($ch);

        if ($result === False) {
            $msg = curl_error($ch);
            $this->_log('error', "Не удалось выполнить запрос: $msg");
            throw new AlbaException("Ошибка подключения к удаленному серверу", 'curl');
        }
        curl_close($ch);

        $answer = json_decode($result);

        if ($answer->status === 'error') {
            $msg = property_exists($answer, 'msg')?$answer->msg:$answer->message;
            $code = property_exists($answer, 'code')?$answer->code:'unknown';
            $this->_log('error', "$msg ($code)");
            throw new AlbaException($msg, $code);
        } else {
            $this->_log('debug', "Получен ответ: $result");
        }

        return $answer;
    }

    /**
     * @brief Полуение списка доступных способов оплаты для сервиса
     * @throw AlbaException
     * @return array список допустимых способов оплаты
     */
    public function payTypes()
    {
        $check = md5($this->service_id . $this->secret);

        $url = static::BASE_URL . "a1lite/pay_types/?service_id=$this->service_id&check=$check";
        $answer = $this->_curl($url);
        return $answer->types;
    }

    /**
     * @brief Инициация оплаты
     * @param string $pay_type способ оплаты
     * @param string $cost сумма платежа
     * @param string $name наименование товара
     * @param string $email e-mail покупателя
     * @param string $order_id идентификатор заказа
     * @throw AlbaException
     * @return array
     */
    public function initPayment($pay_type, $cost, $name, $email, $phone, $order_id=False)
    {
        $fields = array(
            "cost" => $cost,
            "name" => $name,
            "email" => $email,
            "phone_number" => $phone,
            "background" => "1",
            "type" => $pay_type,
            "service_id" => $this->service_id,
            "version" => "2.0"
        );
        if ($order_id !== False) {
            $fields['order_id'] = $order_id;
        }

        $url = static::BASE_URL . "a1lite/input/";

        $fields['check'] = $this->sign(
            "POST",
            $url,
            $fields,
            $this->secret
        );

        $answer = $this->_curl($url, $fields);
        return $answer;
    }

    /**
     * @brief Получение информации о транзакции
     * @param int $tid идентификатор транзакции
     * @return array
     */
    public function transactionDetails($tid)
    {
        $url = static::BASE_URL . "a1lite/details/";
        $fields = array('tid' => $tid,
                        "version" => "2.0");
        $fields['check'] = $this->sign(
            "POST",
            $url,
            $fields,
            $this->secret
        );
        $answer = $this->_curl($url, $fields);
        return $answer;
    }

    /**
     * @brief проведение возврата
     * @param string $gate короткое имя шлюза
     */
    public function refund($tid, $amount, $test=False)
    {
        $url = static::BASE_URL . "a1lite/refund/";
        $fields = array('amount' => $amount,
                        "version" => "2.0",
                        'tid' => $tid);
        if ($test) {
            $fields['test'] = '1';
        }
        $fields['check'] = $this->sign(
            "POST",
            $url,
            $fields,
            $this->secret
        );
        $answer = $this->_curl($url, $fields);
        return $answer;
    }

    /**
     * @brief получение информации о шлюзе
     * @param string $gate короткое имя шлюза
     */
    public function gateDetails($gate)
    {
        $url = static::BASE_URL . "a1lite/gate_details/";
        $fields = array('version' => "2.0",
                        'gate' => $gate,
                        'service_id' => $this->service_id);
        $fields['check'] = $this->sign(
            "GET",
            $url,
            $fields,
            $this->secret
        );
        $answer = $this->_curl($url . "?" . http_build_query($fields));
        return $answer;
    }

    /**
     * @brief Обработка нотификации
     * @param array $post Массив $_POST параметров
     */
    public function checkCallbackSign($post)
    {
        $order = array(
            'tid',
            'name',
            'comment',
            'partner_id',
            'service_id',
            'order_id',
            'type',
            'cost',
            'income_total',
            'income',
            'partner_income',
            'system_income',
            'command',
            'phone_number',
            'email',
            'resultStr',
            'date_created',
            'version',
        );
        $params = array();
        foreach($order as $field) {
            if (isset($post[$field])) {
                $params[] = $post[$field];
            }
        }
        $params[] = $this->secret;
        return md5(implode($params)) === $post['check'];
    }
}


class AlbaCallback {

    /**
     * @param array $services список сервисов от которых ожидаются обратные вызовы
     */
    public function __construct($services)
    {
        $this->services = array();
        foreach($services as $service) {
            $this->services[$service->service_id] = $service;
        }
    }

    /**
     * @brief Обработка нотификаций
     */
    public function handle($post)
    {
        if (isset($post['service_id'])) {
            $service_id = $post['service_id'];
        } else {
            throw new AlbaException('Отсутствует обязательный параметр service_id');
        }

        if (in_array($service_id, array_keys($this->services))) {
            $service = $this->services[$service_id];
            if ($service->checkCallbackSign($post)) {
                $this->callback($post);
            } else {
                throw new AlbaException("Ошибка в подписи");
            }
        } else {
            throw new AlbaException("Неизвестный сервис: $service_id");
        }
    }

    /**
     * @brief Обработка callback после проверки подписи
     */
    public function callback($data)
    {
        if ($data['command'] === 'process') {
            $this->callbackProcess($data);
        } elseif ($data['command'] === 'success') {
            $this->callbackSuccess($data);
        } elseif ($data['command'] === 'recurrent_cancel') {
            $this->callbackRecurrentCancel($data);
        } elseif ($data['command'] === 'refund') {
            $this->callbackRefund($data);
        } else {
            throw new AlbaException("Неожиданный тип уведомления: {$data['command']}");
        }
    }

    /**
     * @brief вызывается при любой (в том числе частичной) оплате сервиса
     */
    public function callbackProcess($data)
    {
    }

    /**
     * @brief вызывается при полной оплате сервиса
     */
    public function callbackSuccess($data)
    {
    }

    /**
     * @brief вызывается в случае, если держатель карты оменил подписку на рекурренты
     */
    public function callbackRecurrentCancel($data)
    {
    }

    /**
     * @brief результат проведения возврата
     */
    public function callbackRefund($data)
    {
    }

}