const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	embed: path.join(__dirname, 'src', 'embed.js'),
	wrapper: path.join(__dirname, 'src', 'wrapper.js'),
}

module.exports = webpackConfig
