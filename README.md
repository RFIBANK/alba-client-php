Библиотека для работы c Alba
=============

Библиотека содержит два базовых класса AlbaService и AlbaCallback предназначенных для наследования.

AlbaService - сервис в Alba. Позволяет получить список доступных способов оплаты, инициировать транзакцию, получать информацию о ней. Необходимо создать по экземпляру на каждый существующий сервис.

AlbaCallback - обработчик для обратного вызова от Alba. Проверяет подпись и вызывает соответствующий параметру "command" метод.

В процессе работы может сработать исключение AlbaException.

Пример использования для инициации транзакции:

       $service = new AlbaService(<service-id>, '<service-secret>');
       try {
           $service->initPayment('mc', 10, 'Test', 'test@example.com', '71111111111');
       } catch (AlbaException $e) {
           echo $e->getMessage();
       }

Пример использования для обратного вызова:

       class MyAlbaCallback extends AlbaCallback {

           public function callbackSuccess($data) {
               // фиксирование успешной транзакции
           }
       }

       $service1 = new AlbaService(<service1-id>, '<service1-secret>');
       $service2 = new AlbaService(<service2-id>, '<service2-secret>');
       $callback = new MyAlbaCallback(array($service1, $service2]));
       $callback->handle(<массив-c-POST-данными>)
       
       
       
Пример использования для проведение рекуррентных платежей:

       $service = new AlbaService(<service-id>, '<service-secret>');
       
       try {
            // Получение токена. 
            // Доступно олько для тех сервисов у которых доступна данная опция
            // Остальные сервисы должны иницировать оплату без использования background API
            $token = $service->createCardToken(
                '4300000000000777', 11, '19', '123', True
            );
            echo "Card token: " . $token;

        } catch (AlbaException $e) {
            echo $e->getMessage();
        }

        try {
            // Инициация первого рекуррентного платежа
            $first_order_id = 'first-' . uniqid();
            $recurrent_params = RecurrentParams::first_pay(
                // Ссылка на подробное описание правил предоставления рекуррентного платежа
                'http://example.com/rules', 
                 // Текстовое описание за что производится регистрация РП	
                'Test'
            );
            $service->initPayment(
                 'spg_test',
                 10,
                 'Test',
                 'test@example.com',
                 '71111111111',
                 $first_order_id, 
                 'partner',
                 $token,
                 $recurrent_params
            );
         } catch (AlbaException $e) {
            echo $e->getMessage();
         }

         try {
            // Инициация последующего рекуррентного платежа
            $recurrent_params = RecurrentParams::next_pay(
                // order_id первого рекуррентного платежа
                $first_order_id  
            );
            $service->initPayment(
                'spg_test',
                10,
                'Test',
                'test@example.com',
                '71111111111',
                uniqid(),  // order_id текущего платежа
                'partner',
                $token,
                $recurrent_params
            );
          } catch (AlbaException $e) {
            echo $e->getMessage();
          }


