const params = new URLSearchParams(window.location.search)
const iframe = document.getElementById('embededIframe')

// HACK: prevent download in case of shared document
window._.defer(() => {
	iframe.src = params.get('url')
})

iframe.onload = () => {
	try {
		console.debug('[watcha] iframe URL:', iframe.contentWindow.location.href)
	} catch (error) {
		if (error instanceof DOMException) {
			handleSsoRedirection()
		}
	}
}

/**
 *
 */
function handleSsoRedirection() {
	console.debug('[watcha] SSO redirection')
	iframe.style.visibility = 'visible'
	const checkSso = setInterval(() => {
		try {
			Object.prototype.hasOwnProperty.call(iframe.contentWindow.location, 'origin')
			iframe.style.visibility = 'hidden'
			clearInterval(checkSso)
			console.debug('[watcha] SSO done')
		} catch (error) {
			if (!(error instanceof DOMException)) {
				throw error
			}
		}
	}, 200)
}
