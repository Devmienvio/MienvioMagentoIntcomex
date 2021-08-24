##MienvioMagento

Versión Intcomex Enterprise.

El plugin contiene métodos y ajustes específicos para funcionar con la linea de negocio de B2B y B2C.



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
    

