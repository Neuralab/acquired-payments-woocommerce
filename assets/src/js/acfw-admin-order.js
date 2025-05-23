/**
 * Admin order.
*/

/* eslint-disable camelcase */

const submit = document.querySelector('#woocommerce-order-actions .wc-reload');
const select = document.querySelector('#woocommerce-order-actions [name="wc_order_action"]');

if (submit && select) {
  const actions = {
    acfw_capture_payment: window.wp.i18n.__('Are you sure you want to capture this payment? This action can\'t be undone.', 'acquired-com-for-woocommerce'),
    acfw_cancel_order: window.wp.i18n.__('Are you sure you want to cancel this order? This action can\'t be undone.', 'acquired-com-for-woocommerce'),
  };

  submit.addEventListener('click', event => {
    if (!(select.value in actions)) {
      return;
    }

    // eslint-disable-next-line no-alert
    if (!window.confirm(actions[select.value])) {
      event.preventDefault();
    }
  });
}
