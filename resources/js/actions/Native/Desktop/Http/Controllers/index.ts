import NativeAppBootedController from './NativeAppBootedController'
import DispatchEventFromAppController from './DispatchEventFromAppController'
import CreateSecurityCookieController from './CreateSecurityCookieController'

const Controllers = {
    NativeAppBootedController: Object.assign(NativeAppBootedController, NativeAppBootedController),
    DispatchEventFromAppController: Object.assign(DispatchEventFromAppController, DispatchEventFromAppController),
    CreateSecurityCookieController: Object.assign(CreateSecurityCookieController, CreateSecurityCookieController),
}

export default Controllers