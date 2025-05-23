/**
 * Block checkout.
*/

const paymentMethodID = 'acfw';
const settings = window.wc.wcSettings.getSetting(`${paymentMethodID}_data`, {});
const label = window.wp.htmlEntities.decodeEntities(settings.title);

const Content = () => window.wp.htmlEntities.decodeEntities(settings.description);

const options = {
  name: paymentMethodID,
  label,
  content: window.wp.element.createElement(Content, {}),
  edit: window.wp.element.createElement(Content, {}),
  canMakePayment: () => true,
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(options);
