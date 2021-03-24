// Import all necessary Storefront plugins and scss files
import ASAppointment from './a-s-appointment/a-s-appointment.plugin';

// Register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('ASAppointment', ASAppointment, '[data-a-s-appointment]');