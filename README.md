##MienvioMagento

Versión Intcomex Enterprise.

El plugin contiene métodos y ajustes específicos para funcionar con la linea de negocio de B2B y B2C.

## V.3.1.11
2022/05/12


#### Updates V.3.1.11 :

- Se implementa correción para enviar datos dinamicos del cliente en cotización
- Se agrega la descripción dinamica para tarifario Master de GT


## V.3.1.10
2021/09/02


#### Updates V.3.1.10 :

- Se implementa validacion para evitar la impresion del nombre del courrier en caso de FilterByCost

## V.3.1.9
2021/08/24


#### Updates V.3.1.9 :

- Se Intercambian niveles Geograficos para colombia intercambiando $destCity por $destRegionCode

## V.3.1.8
2021/08/24


#### Updates V.3.1.8 :

- Cambio de Mensaje en el guardado de la orden, detallando el order id

## V.3.1.7
2021/08/24


#### Updates V.3.1.7 :

- Actualizacion de Campo mienvio_trax_id en base de datos via UpgradeData


## V.3.1.6
2021/08/24


#### Updates V.3.1.6 :

- Manejo de excepciones en almacenado de la orden y obtencion del producto.


## V.3.1.5
2021/08/24


#### Updates V.3.1.5 :

- Manejo de excepciones en el guardado de la orden de ObserverSuccess



## V.3.1.4
2021/08/23


#### Updates V.3.1.4 :

- Producto obtenido mediante su SKU mediante el componente productFactory
- Filtrado de lista por precios economicos enviado a mienvio

## V.3.1.3
2021/08/19



#### Updates V.3.1.3 : 

-  campo filter_list agregado y configurado, se envia desde el plugin a mienvio el parametro filter list, parea saber si se filtra por el costo más bajo las diferentes tarifas
-  Almacenamiento de mienvio_trax_id en tabla de sales_orders. Se requiere 
    - Borrar el registro MienvioMagento_MienvioGeneral de la tabla setup_module 
    - php bin/magento setup:upgrade
    - bin/magento setup:di:compile
    

