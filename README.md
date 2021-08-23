##MienvioMagento

Versión Intcomex Enterprise.

El plugin contiene métodos y ajustes específicos para funcionar con la linea de negocio de B2B y B2C.

## V.3.1.4
2021/08/19


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
    

