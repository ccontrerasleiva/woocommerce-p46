<?php 
/*
 * Plugin Name: WooCommerce Pago 46
 * Plugin URI: https://github.com/ccontrerasleiva/woocommerce-p46
 * Description: Permite pagos usando Pago46 
 * Author: Cristian Contreras
 * Author URI: https://github.com/ccontrerasleiva
 * Version: 1.0
 */

add_filter( 'woocommerce_payment_gateways', 'p46_add_gateway_class' );

function p46_add_gateway_class($gateways){
    $gateways[] = 'WC_Gateway_pago46'; 
    return $gateways;
}

add_action( 'plugins_loaded', 'p46_init_gateway_class' );

function p46_init_gateway_class() {
    class WC_Gateway_pago46 extends WC_Payment_Gateway {

        const SANDBOX_URL = 'https://sandbox.pago46.com';
        const PROD_URL = 'https://api.pago46.com';
        const ALG = 'sha256';
        
        public function __construct() {
            $this->id = 'pago46';
            $this->has_fields = false; 
            $this->method_title = 'Pago 46';
            $this->icon = plugin_dir_url( __FILE__ ).'logo.png'; 
            $this->method_description = 'Configuración Pago46';
            $this->supports = array(
                'products'
            );
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option( 'p46_title' );
            $this->description = $this->get_option( 'p46_description' );
            $this->sandbox = 'yes' == $this->get_option( 'p46_env' );
            $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/'));
            $this->endpoint = $this->sandbox ? self::SANDBOX_URL : self::PROD_URL;
            $this->key = $this->get_option( 'p46_key' );
            $this->secret = $this->get_option( 'p46_secret' );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_response'));
        }

        public function init_form_fields(){
            $this->form_fields = array(
                'p46_title' => array(
                    'title'       => 'Título',
                    'type'        => 'text',
                    'description' => 'Controla el titulo del medio de pago en la pagina de checkout',
                    'default'     => 'Pago 46',
                    'desc_tip'    => true,
                ),
                'p46_description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Indica el descriptor del medio de pago en la pagina de checkout',
                    'default'     => 'Paga con Pago46',
                ),
                'p46_env' => array(
                    'title'       => 'Usar en Sandbox',
                    'type'        => 'checkbox',
                    'description' => 'Si esta activado, las transacciones se ejecutaran en modo de pruebas, no seran transacciones reales',
                    'default'     => 'no',
                    'desc_tip'    => true,
                ),
                'p46_key' => array(
                    'title'       => 'Llave Privada',
                    'type'        => 'text',
                    'description' => 'Llave privada asociada a tu comercio'
                ),
                'p46_secret' => array(
                    'title'       => 'Secreto compartido',
                    'type'        => 'text',
                    'description' => 'Secreto compartido de tu comercio'
                ),
            );
        }

        private function encodeURIComponent($str) {
            $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
            return strtr(rawurlencode($str), $revert);
        }
        

        public function process_payment($order_id){
            global $woocommerce;
            $order = new WC_Order( $order_id );

            $order_currency = "{$order->get_currency()}"; 
            $order_description = "Pago Orden Numero {$order_id}";
            $order_price = number_format($order->get_total(), 2, ".", "");
            $order_timeout = 60; 
            $merchant_notify_url = $this->notify_url;
            $merchant_return_url = $this->get_return_url( $order );
            $request_method = "POST";
            $request_path = "/merchant/orders/"; 

            $params = [
                'currency' => $order_currency,
                'description' => $this->encodeURIComponent($order_description),
                'merchant_order_id' => $order_id,
                'notify_url' => $this->encodeURIComponent($merchant_notify_url),
                'price' => $order_price,
                'return_url' => $this->encodeURIComponent($merchant_return_url),
                'timeout' => $order_timeout
            ];
            $date = time();
            $params_string = '';

            foreach($params as $key=>$value) { $params_string .= $key.'='.$value.'&'; }

            $params_string = rtrim($params_string, '&');

            $encrypt_base = $this->key . '&' . $date .'&' .$request_method . '&' . $this->encodeURIComponent($request_path) . '&'. $params_string;


            $hash = hash_hmac('sha256',$encrypt_base, $this->secret);

            try{

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $this->endpoint.$request_path);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);

                $headers = [
                    'merchant-key: '.$this->key,
                    'message-hash: '.$hash,
                    'message-date:'.$date,
                ];

                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $server_output = curl_exec ($ch);

                curl_close ($ch);

                $ret = json_decode($server_output);
                if($ret){
                    $order->update_status('on-hold', 'Esperando confirmación de pago desde Pago46');
                    $order->update_meta_data( 'p46_id', $ret->id );
                    $order->update_meta_data( 'p46_status', $ret->status );
                    $order->save();
                    return array(
                        'result' => 'success',
                        'redirect' => $ret->redirect_url
                    );
                }
            }
            catch(Exception $e){
                $e->hasResponse() ? wc_add_notice($e->getResponse(), 'error' ) : wc_add_notice('Ha ocurrido un error inesperado, favor intente nuevamente mas tarde', 'error' );
                return array(
                    'result' => 'failure',
                    'redirect' => ''
                );
            }

        }
        public function check_response(){
            global $woocommerce;
            $date = time();
            $request_data = json_decode(file_get_contents( 'php://input' ));
            $request_path = "/merchant/notification/".$request_data->notification_id;
            $encrypt_base = $this->key . '&' . $date .'&GET&' . $this->encodeURIComponent($request_path) ;
            $hash = hash_hmac('sha256',$encrypt_base, $this->secret);
            $headers = [
                'merchant-key: '.$this->key,
                'message-hash: '.$hash,
                'message-date:'.$date,
            ];
            try{

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $this->endpoint.$request_path);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $server_output = curl_exec ($ch);

                curl_close ($ch);

                $ret = json_decode($server_output);
                if($ret){
                    $order = new WC_Order( $ret->merchant_order_id );
                    if($order){
                        http_response_code(200);
                        if ($order->get_status() == 'completed' || $order->get_status() == 'processing') {
                            exit('{"result" : "Order already processed"}');
                        }
                        else{
                            $order->update_meta_data( 'p46_fecha_operacion', $ret->creation_date );
                            if($ret->status == 'successful'){
                                $order->update_status( 'processing' );
                                $order->add_order_note( sprintf( __( 'Pago 46 - Pago aprobado. <br />ID Transacción: %s <br /> Fecha Operacion: %s ', 'woocommerce' ), $ret->id, date('d/m/Y H:i', strtotime($ret->creation_date)) ) );
                                exit('{"result" : "Order processed"}');
                                
                            }
                            if($ret->status == 'pending'){
                                $order->update_status( 'pending' );
                                $order->add_order_note( sprintf( __( 'Pago 46 - Pago pendiente. <br />ID Operación: %s <br /> Fecha Operacion: %s ', 'woocommerce' ), $ret->id, date('d/m/Y H:i', strtotime($ret->creation_date)) ) );
                                exit('{"result" : "Order Pending"}');
                                
                            }
                            if($ret->status == 'cancel'){
                                $order->update_status( 'cancelled' );
                                $order->add_order_note( sprintf( __( 'Pago 46 - Orden Cancelada por el usuario. <br />ID Operación: %s <br /> Fecha Operacion: %s ', 'woocommerce' ), $ret->id, date('d/m/Y H:i', strtotime($ret->creation_date)) ) );
                                exit('{"result" : "Order Cancelled"}');
                                
                            }
                            if($ret->status == 'awaiting_assignment'){
                                $order->add_order_note( sprintf( __( 'Pago 46 - Orden A espera de asignación. <br />ID Operación: %s <br /> Fecha Operacion: %s ', 'woocommerce' ), $ret->id, date('d/m/Y H:i', strtotime($ret->creation_date)) ) );
                                exit('{"result" : "Order Awaiting Assignment"}');
                            }
                            if($ret->status == 'expired'){
                                $order->update_status( 'failed' );
                                $order->add_order_note( sprintf( __( 'Pago 46 - Orden Expirada. <br />ID Operación: %s <br /> Fecha Operacion: %s ', 'woocommerce' ), $ret->id, date('d/m/Y H:i', strtotime($ret->creation_date)) ) );
                                exit('{"result" : "Order Expired"}');
                            }
                            
                        }
                        
                    }
                }
            }
            catch(Exception $e){
                http_response_code(400);
                error_log("Error: {$e->getMessage()}");
                exit("{'result : '{$e->getMessage()}'}");
            }

            
        }

    }
}