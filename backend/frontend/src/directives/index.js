/**
 * 全局指令注册
 */
import permission from './permission'

export default {
  install(app) {
    app.directive('permission', permission)
  }
}

export { permission }
