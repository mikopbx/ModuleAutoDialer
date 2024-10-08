﻿
&НаКлиенте
Процедура ВопросыПриАктивизацииСтроки(Элемент)
	ТекущиеДанные = Элементы.Вопросы.ТекущиеДанные;
	Если ТекущиеДанные = Неопределено Тогда
		Возврат;
	КонецЕсли;
	
	Элементы.ВариантыОтветов.Видимость = Истина;
	Элементы.ВариантыОтветов.ОтборСтрок = Новый ФиксированнаяСтруктура("questionId", ТекущиеДанные.questionId);	
КонецПроцедуры

&НаКлиенте
Процедура ВопросыПриОкончанииРедактирования(Элемент, НоваяСтрока, ОтменаРедактирования)
	ТекущиеДанные = Элементы.Вопросы.ТекущиеДанные;
	Если ТекущиеДанные = Неопределено Тогда
		Возврат;
	КонецЕсли;
	
	Если НЕ ЗначениеЗаполнено(ТекущиеДанные.questionId) Тогда
		ТекущиеДанные.questionId = Объект.СчетчикВопросов;
		Объект.СчетчикВопросов = Объект.СчетчикВопросов + 1;
	КонецЕсли;
	
КонецПроцедуры

&НаКлиенте
Процедура ВариантыОтветовПриОкончанииРедактирования(Элемент, НоваяСтрока, ОтменаРедактирования)
	ТекущийВопрос = Элементы.Вопросы.ТекущиеДанные;
	Если ТекущийВопрос = Неопределено Тогда
		ОтменаРедактирования = Истина;
		Возврат;
	КонецЕсли;
	
	Для каждого ТекущиеДанные Из Объект.ВариантыОтветов Цикл
		Если НЕ ЗначениеЗаполнено(ТекущиеДанные.questionId) Тогда
			ТекущиеДанные.questionId = ТекущийВопрос.questionId;
		КонецЕсли;
	КонецЦикла;
	
КонецПроцедуры

&НаКлиенте
Процедура ВопросыПослеУдаления(Элемент) 
	
	СтрокиКУдалению = Новый Массив();
	Для каждого ТекущиеДанные Из Объект.ВариантыОтветов Цикл
		Если Объект.Вопросы.НайтиСтроки(Новый Структура("questionId", ТекущиеДанные.questionId)).Количество()=0 Тогда
			СтрокиКУдалению.Добавить(Объект.ВариантыОтветов.Индекс(ТекущиеДанные));
		КонецЕсли;
		Если Объект.Вопросы.НайтиСтроки(Новый Структура("questionId", ТекущиеДанные.nextQuestion)).Количество()=0 Тогда
			ТекущиеДанные.nextQuestion = "";
		КонецЕсли;            
	КонецЦикла; 
	
	Для каждого Индекс Из СтрокиКУдалению Цикл
	 	Объект.ВариантыОтветов.Удалить(Индекс);
	КонецЦикла;
		
КонецПроцедуры
