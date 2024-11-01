<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Класс гейта оплаты через Платёжку
 *
 */
class WC_Gateway_Ym_Billing extends WC_Payment_Gateway
{
    /**
     * @var string Идентификатор Платежки
     */
    public $billing_id;

    /**
     * @var string Шаблон назначения платежа
     */
    public $billing_purpose;

    /**
     * @var string Статус на который изменяется статус заказа при проведении платежа
     */
    public $billing_status;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->id = 'ym-billing';
        $this->icon = apply_filters('woocommerce_ym_billing_icon', '');
        $this->has_fields = true;
        $this->method_title = __('Ym_Billing', 'ym-billing');
        $this->method_description = __('Allows payments by Billing.', 'ym-billing');

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');

        $this->billing_id = $this->get_option('billing_id');
        $this->billing_purpose = $this->get_option('billing_purpose');
        $this->billing_status = $this->get_option('billing_status');

        // хук на сохранение настроке платёжного модуля
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // хук на отображение формы оплаты заказа
        add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page'));

        // хук на проверку возможности оплаты заказа, определяется по его состоянию
        add_filter('woocommerce_order_needs_payment', array($this, 'checkPaymentStatus'), 10, 2);

        // Customer Emails
        //add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
    }

    /**
     * Фильтр, позволяющий отображать форму оплаты для заказов в состоянии, указанном в настройках
     *
     * Если статус заказа тот, который указан в админке, в который заказ переводится при оплате через Платёжку,
     * то разрешаем оплачивать.
     *
     * @param bool $flag Флаг, полученный в самом заказе (позволяет оплачивать pending и failed заказы)
     * @param WC_Order $order Инстанс оплачиваемого заказа
     * @return bool True если заказ оплачивать можно, false если нет
     */
    public function checkPaymentStatus($flag, $order)
    {
        return $flag ? true : ($order->get_status() == $this->billing_status);
    }

    /**
     * Инициализирует настройки формы редактирования текущего платёжного гейта
     */
    public function init_form_fields()
    {
        $options = array();
        foreach (wc_get_order_statuses() as $key => $value) {
            if (strncmp('wc-', $key, 3) === 0) {
                // у статусов в начале идёт подстрока "wc-", хотя везде статус устанавливается без префикса
                // поэтому префикс выпиливаем
                $options[substr($key, 3)] = $value;
            } else {
                $options[$key] = $value;
            }
        }
        $this->form_fields = array(
            'enabled'         => array(
                'title'   => __('Enable/Disable', 'ym-billing'),
                'type'    => 'checkbox',
                'label'   => __('Activate payments via Billing', 'ym-billing'),
                'default' => 'no',
            ),
            'title'           => array(
                'title'       => __('Title', 'ym-billing'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'ym-billing'),
                'default'     => __('Billing (bank card, e-wallets)', 'ym-billing'),
                'desc_tip'    => true,
            ),
            'billing_id' => array(
                'title'   => __('Billing\'s identifier', 'ym-billing'),
                'type'    => 'text',
                'default' => '',
            ),
            'billing_purpose' => array(
                'title'       => __('Payment purpose', 'ym-billing'),
                'type'        => 'text',
                'default'     => __('Order No. %order_is% Payment via Billing', 'ym-billing'),
                'description' => __(
                    'Payment purpose is added to the payment order: specify whatever will help identify the '
                        . 'order paid via Billing',
                    'ym-billing'
                ),
                'desc_tip'    => true,
            ),
            'billing_status' => array(
                'title'       => __('Order status', 'ym-billing'),
                'type'        => 'select',
                'default'     => 'processing',
                'options'     => $options,
                'description' => __(
                    'Order status shows the payment result is unknown: you can only learn whether the client '
                        . 'made payment or not from an email notification or in your bank',
                    'ym-billing'
                ),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Метод вызывается при отображении настроек модуля в админке для генерации формы редактирования
     */
    public function admin_options()
    {
        echo '<h3>' . $this->method_title . '</h3>'
            . '<h5>' . __(
                'This is a payment form for your site. It allows for accepting payments to your company '
                    . 'account from cards and Yandex.Money e-wallets without a contract. To set it up, '
                    . 'you need to provide the Billing identifier: we will send it via email after '
                    . 'you create a form in construction kit.',
                'ym-billing'
            ) . '</h5>'
            . '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * Выводит в поток вывода форму, в которую пользователь вводит данные на странице выбора типа платежа
     */
    public function payment_fields()
    {
        $fio = $this->fetchFullName();
        ?>
        <label for="ym-billing-fio"><?php echo __('Payer\'s full name', 'ym-billing'); ?></label>
        <input type="text" id="ym-billing-fio" name="billing_fio" value="<?php echo wc_clean($fio); ?>" />
        <div style="display:none;" id="ym-billing-fio-error">Укажите фамилию, имя и отчество плательщика</div>
        <?php
    }

    /**
     * Метод валидации формы оплаты через Платёжку
     * @return bool True если все значения валидны, false если нет
     */
    public function validate_fields()
    {
        if (empty($_POST['billing_fio'])) {
            wc_add_notice(__('Payer\'s full name is empty', 'ym-billing'), 'error');
            return true;
        } elseif (!$this->validatePayerName($_POST['billing_fio'])) {
            wc_add_notice(__('Invalid payer\'s full name', 'ym-billing'), 'error');
            return true;
        }
        return true;
    }

    /**
     * Метод отображения формы оплаты заказа через Платёжку
     * @param int $order_id Айди уже созданного, но не оплаченного заказа
     */
    public function receipt_page($order_id)
    {
        if (!(WC()->cart->is_empty())) {
            WC()->cart->empty_cart();
        }

        $order = new WC_Order($order_id);
        if ($this->id == $order->get_payment_method() && $this->billing_status != 'on-hold') {
            $order->update_status($this->billing_status, __('Awaiting Billing payment', 'ym-billing'));
        }

        $narrative = $this->parsePlaceholders($this->billing_purpose, $order);
        ?>
        <form method="post" action="https://money.yandex.ru/fastpay/confirm" id="ym-billing-form">
            <input type="hidden" name="formId" value="<?php echo $this->billing_id; ?>" />
            <input type="hidden" name="narrative" value="<?php echo $narrative; ?>" />
            <input type="hidden" name="sum" value="<?php echo $order->get_total(); ?>" />
            <input type="hidden" name="fio" value="<?php echo htmlspecialchars($order->get_meta('ym-billing-fio')); ?>" />
            <input type="hidden" name="quickPayVersion" value="2" />
            <input type="hidden" name="cms_name" value="woocommerce-ya-billing" />
        </form>
        <button class="added_to_cart wc-forward" id="ym-billing-pay"><?php echo __('Pay order') ?></button>
        <script type="text/javascript">
            jQuery(document).ready(function () {
                jQuery('#ym-billing-pay').bind('click', function (e) {
                    document.getElementById('ym-billing-form').submit();
                });
            });
        </script>
        <?php
    }

    /**
     * Метод осуществляет установку того, что заказ оплачивается через Платёжку
     * @param int $order_id Айди оплачиваемого заказа
     * @return array Массив с информацией о статусе установки типа платёжки
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!empty($_POST['billing_fio']) && $this->validatePayerName($_POST['billing_fio'])) {
            $order->update_meta_data('ym-billing-fio', htmlspecialchars($_POST['billing_fio']));
            $order->update_status('pending', __('Awaiting Billing payment', 'ym-billing'));
            wc_reduce_stock_levels($order_id);

            $order->save();

            $returnURL = $order->get_checkout_payment_url(true);
            return array(
                'result'   => 'success',
                'redirect' => $returnURL,
            );
        }

        $returnURL = $order->get_view_order_url();
        return array(
            'result'   => 'failure',
            'redirect' => $returnURL,
        );
    }

    /**
     * Валидирует имя плательщика
     * @param string $name Имя плательщика в виде строки
     * @return bool True если имя плательщика валидно, false если нет
     */
    private function validatePayerName($name)
    {
        $parts = explode(' ', $name);
        if (count($parts) != 3) {
            return false;
        }
        foreach ($parts as $part) {
            if (empty($part)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Парсит шаблон назначения платежа и подставляет в него параметры из заказа
     * @param string $tpl Шаблон назначения платежа
     * @param WC_Order $order Инстанс оплачиваемого заказа
     * @return string Готовая строка для отправки в Платёжку
     */
    private function parsePlaceholders($tpl, $order)
    {
        $replace = array(
            '%order_id%' => $order->get_id(),
        );
        foreach ($order->get_data() as $key => $value) {
            if (is_scalar($value)) {
                $replace['%'.$key.'%'] = $value;
            }
        }
        return strtr($tpl, $replace);
    }

    /**
     * Возвращает полное имя плательщика, если заказ уже создан, получает имя из него, если нет, то из пользователя
     * @return string Полное имя плательщика
     */
    private function fetchFullName()
    {
        $order_id = WC()->session->get('order_awaiting_payment', -1);
        if ($order_id > 0) {
            $order = new WC_Order($order_id);
            $meta = $order->get_meta('ym-billing-fio');
            if (!empty($meta)) {
                return $meta;
            }
        }
        /** @var WC_Customer $customer Пользователь который покупает товар в магазине */
        $customer = WC()->customer;
        $fio = array();

        $val = $customer->get_billing_last_name();
        if (empty($val)) {
            $val = $customer->get_last_name();
        }
        if (!empty($val)) {
            $fio[] = $val;
        }

        $val = $customer->get_billing_first_name();
        if (empty($val)) {
            $val = $customer->get_first_name();
        }
        if (!empty($val)) {
            $fio[] = $val;
        }
        return implode(' ', $fio);
    }
}