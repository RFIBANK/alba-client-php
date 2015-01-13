Библиотека для работы c Alba
=============

Библиотека содержит два базовых класса AlbaService и AlbaCallback предназначенных для наследования.

AlbaService - сервис в Alba. Позволяет получить список доступных способов оплаты, инициировать транзакцию, получать информацию о ней. Необходимо создать по экземпляру на каждый существующий сервис.

AlbaCallback - обработчик для обратного вызова от Alba. Проверяет подпись и вызывает соответствующий параметру "command" метод.

В процессе работы может сработать исключение AlbaException.

Пример использования для инициации транзакции:

       $service = new AlbaService(<service-id>, '<service-name>', '<service-secret>');
       try {
           $service->init_payment('mc', 10, 'Test', 'test@example.com', '71111111111');
       } catch (AlbaException $e) {
           echo $e->getMessage();
       }

Пример использования для обратного вызова:

       class MyAlbaCallback extends AlbaCallback {

           public function callbackSuccess($data) {
               // фиксирование успешной транзакции
           }
       }

       $service1 = new AlbaService(<service1-id>, '<service1-name>', '<service1-secret>');
       $service2 = new AlbaService(<service2-id>, '<service2-name>', '<service2-secret>');
       $callback = new MyAlbaCallback(array($service1, $service2]));
       $callback->handle(<массив-c-POST-данными>)