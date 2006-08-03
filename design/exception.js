function getElementsByClassName(oElm, strTagName, strClassName) {
	// Written by Jonathan Snook, http://www.snook.ca/jon;
	// Add-ons by Robert Nyman, http://www.robertnyman.com
	var arrElements = (strTagName == "*" && document.all)? document.all : oElm.getElementsByTagName(strTagName);
	var arrReturnElements = new Array();
	strClassName = strClassName.replace(/\-/g, "\\-");
	var oRegExp = new RegExp("(^|\\s)" + strClassName + "(\\s|$)");
	var oElement;
	for(var i=0; i<arrElements.length; i++) {
		oElement = arrElements[i];
		if(oRegExp.test(oElement.className)) {
			arrReturnElements.push(oElement);
		}
	}
	return (arrReturnElements)
}

function hideAll(elems) {
	for (var e = 0; e < elems.length; e++) {
		elems[e].style.display = 'none';
	}
}

function toggle() {
	for (var i = 0; i < arguments.length; i++) {
		var e = document.getElementById(arguments[i]);
		if (e) {
			e.style.display = e.style.display == 'none' ? 'block' : 'none';
		}
	}
	return false;
}

function varToggle(link, id, prefix) {
	toggle(prefix + id);
	var s = link.getElementsByTagName('span')[0];
	var uarr = String.fromCharCode(0x25b6);
	var darr = String.fromCharCode(0x25bc);
	s.innerHTML = s.innerHTML == uarr ? darr : uarr;
	return false;
}

function sectionToggle(span, section) {
	toggle(section);
	var tspan = document.getElementById(span);
	var uarr = String.fromCharCode(0x25b6);
	var darr = String.fromCharCode(0x25bc);
	tspan.innerHTML = tspan.innerHTML == uarr ? darr : uarr;
	return false;
}

window.onload = function() {
	hideAll(getElementsByClassName(document, 'table', 'vars'));
	hideAll(getElementsByClassName(document, 'div', 'context'));
	hideAll(getElementsByClassName(document, 'ul', 'traceback'));
	hideAll(getElementsByClassName(document, 'div', 'section'));
}
